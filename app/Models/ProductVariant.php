<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use App\Observers\ProductVariantObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One purchasable CAN of a product — "BOYSEN Latex Colors, Burnt Sienna (4L)".
 *
 * A variant is a (colour × size) combination. Price, stock, and the
 * low-stock threshold live HERE, not on Product: a 4L of Burnt Sienna and a
 * 1L of it are separate things on the shelf, and so are two shades of the
 * same size. Colour is '' on products sold in no particular colour (thinners,
 * tools). On a mixing base the colour NAME says which base the can is
 * ("White Base", "Deep Base") and hex_code is the base's own colour.
 */
#[ObservedBy(ProductVariantObserver::class)]
class ProductVariant extends Model
{
    use HasFactory, TracksActivity;

    public function activityTitle(): string
    {
        return $this->display_name;
    }

    /** Stock moves are audited in full by InventoryLog — don't double-log them here. */
    public function activityIgnored(): array
    {
        return ['stock'];
    }

    protected $fillable = [
        'product_id',
        'sort_order',  // the admin's arrangement — see Product::variants()
        'color_code',  // manufacturer code, e.g. "B-1408"; '' = no code
        'color_name',  // manufacturer name, e.g. "Burnt Sienna"; '' = unnamed
        'hex_code',    // screen preview only — never a colour measurement
        'size_volume',
        'volume_liters', // litres in one can, as a number; see $liters
        'base_type',   // mixing bases only: a key of config('paint.mix.base_types')
        'price',
        'max_tint_ml_per_liter', // mixing bases only; null = config default
        'stock',
        'low_stock_threshold',
        'is_archived',
    ];

    protected $casts = [
        'price' => 'float',
        'volume_liters' => 'float',
        'max_tint_ml_per_liter' => 'float',
        'stock' => 'integer',
        'is_archived' => 'boolean',
    ];

    protected $appends = ['is_low_stock', 'stock_status', 'has_color', 'color_key', 'color_label', 'liters'];

    /**
     * Codes pasted from manufacturer sites and PDFs carry Unicode dashes
     * (‑ – —) that silently fail to match an ASCII hyphen on search. Stored
     * normalized, and '' rather than null — the identity unique index spans
     * this column and MySQL treats NULLs there as all different.
     */
    public function setColorCodeAttribute(?string $value): void
    {
        $this->attributes['color_code'] = Product::normalizeColorCode($value) ?? '';
    }

    /**
     * Keep volume_liters honest. It is filled from size_volume when empty, and
     * RE-derived when the size changes without the volume changing with it —
     * otherwise a can re-labelled from "1L" to "4L" keeps capping its tint at
     * one litre's worth. An explicit volume in the same save always wins.
     *
     * A new size that carries no number ("Gallon") leaves the volume alone.
     * Clearing it would wipe a correct 3.785 — and the admin form resubmits an
     * unchanged volume, which is not dirty, so a base would lose its volume
     * AFTER the form had validated it as required.
     */
    protected static function booted(): void
    {
        // A typed base takes its preview colour and its name from the type.
        // They are never typed by hand: a deep base given a bright white hex
        // would preview every mix in it wrongly, with nothing to show why.
        static::saving(function (self $variant) {
            if ($type = $variant->baseType()) {
                $variant->hex_code = $type['hex'];
                $variant->color_name = $type['label'];
                $variant->color_code = '';
            }
        });

        static::saving(function (self $variant) {
            $sizeChanged = $variant->isDirty('size_volume') && ! $variant->isDirty('volume_liters');

            if ($variant->volume_liters === null || $sizeChanged) {
                $parsed = $variant->parsedLiters();

                if ($parsed !== null) {
                    $variant->volume_liters = round($parsed, 3);
                }
            }
        });
    }

    public function setColorNameAttribute(?string $value): void
    {
        $this->attributes['color_name'] = trim((string) $value);
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class)->latest();
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    public function getIsLowStockAttribute(): bool
    {
        return $this->stock <= $this->low_stock_threshold;
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock === 0) {
            return 'out_of_stock';
        }

        if ($this->is_low_stock) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /** False for thinners, tools, and cans of untinted base. */
    public function getHasColorAttribute(): bool
    {
        return $this->color_code !== '' || $this->color_name !== '';
    }

    /** Groups the variants of one shade together. See Product::$colors. */
    public function getColorKeyAttribute(): string
    {
        return $this->color_code.'|'.$this->color_name;
    }

    /** "Burnt Sienna (B-1408)", or whichever half exists. '' if neither. */
    public function getColorLabelAttribute(): string
    {
        if ($this->color_name !== '' && $this->color_code !== '') {
            return "{$this->color_name} ({$this->color_code})";
        }

        return $this->color_name !== '' ? $this->color_name : $this->color_code;
    }

    /**
     * How much paint is in ONE can of this variant, in litres. Null when
     * nothing measurable is known ("Set of 3", "Large").
     *
     * The stored volume_liters wins: it is a number the admin confirmed. The
     * size_volume parse is only the fallback for rows nobody has filled in.
     *
     * Used to weight the mix prediction, to cap the tint a base accepts, and to
     * state the recipe on the counter's sheet. NOT used for stock, which
     * counts cans.
     */
    public function getLitersAttribute(): ?float
    {
        return $this->volume_liters ?? $this->parsedLiters();
    }

    /**
     * The most colorant one can of this base accepts, in ml. Null when the
     * can's volume is unknown — such a can cannot be a mixing base.
     *
     * The can's own figure wins (it is printed on the can); then its base
     * type's default; then the global default.
     */
    public function maxTintMl(): ?float
    {
        $liters = $this->liters;

        if ($liters === null) {
            return null;
        }

        $perLiter = $this->max_tint_ml_per_liter
            ?? ($this->baseType()['max_tint_ml_per_liter'] ?? null)
            ?? (float) config('paint.mix.default_max_tint_ml_per_liter');

        return round($perLiter * $liters, 1);
    }

    /** This can's base type from config, or null (not a typed base). */
    public function baseType(): ?array
    {
        return $this->base_type ? (config('paint.mix.base_types')[$this->base_type] ?? null) : null;
    }

    /**
     * How much harder colorant pulls in this base than in a white one — the
     * multiplier on every colorant's K/S in the prediction. 1.0 for white and
     * for anything untyped. See config/paint.php, "Base types".
     */
    public function tintResponse(): float
    {
        return (float) ($this->baseType()['tint_response'] ?? 1.0);
    }

    /** [key => label] for the admin's Base type select. */
    public static function baseTypeOptions(): array
    {
        return array_map(fn ($t) => $t['label'], config('paint.mix.base_types'));
    }

    /**
     * Litres read off the free-text size_volume. size_volume is typed by the
     * admin, so this matches rather than parses — including the word "pint",
     * which is a can size here and not a number. The value lives in
     * config/paint.php because a pint is 0.473 L in the US and 0.568 L
     * imperial, and the shop's cans decide which.
     */
    public function parsedLiters(): ?float
    {
        $raw = strtolower(trim((string) $this->size_volume));

        if ($raw === '') {
            return null;
        }

        // Named can sizes first — they carry no number to parse.
        if (preg_match('/\b(pint|pt)\b/', $raw)) {
            return (float) config('paint.mix.pint_liters');
        }

        // The unit is REQUIRED. Without it "Set of 3" reads as three litres
        // and quietly skews a recipe by the volume of a whole base can.
        if (! preg_match('/([0-9]*\.?[0-9]+)\s*(ml|ltr|litres?|liters?|gal|gallons?|l)\b/', $raw, $m)) {
            return null;
        }

        $n = (float) $m[1];
        $unit = $m[2];

        return match (true) {
            $unit === 'ml' => $n / 1000,
            str_starts_with($unit, 'gal') => $n * 3.785411784,
            default => $n,
        };
    }

    /** "Boysen — Latex Colors · Burnt Sienna (4L)" for alerts and admin modals. */
    public function getDisplayNameAttribute(): string
    {
        $brand = $this->product?->brand?->brand_name;
        $color = $this->color_label;

        return trim(
            ($brand ? "{$brand} — " : '').
            ($this->product?->name ?: 'Unknown product').
            ($color !== '' ? " · {$color}" : '').
            " ({$this->size_volume})"
        );
    }

    // -------------------------------------------------------
    // Scopes
    // -------------------------------------------------------

    /** Variants customers can currently see (their product too). */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false)
            ->whereHas('product', fn ($q) => $q->where('is_archived', false));
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'low_stock_threshold');
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock', 0);
    }
}
