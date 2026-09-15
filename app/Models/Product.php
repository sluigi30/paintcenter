<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Product identity (name, brand, categories, images).
 *
 * Everything a customer CHOOSES lives in ProductVariant — colour, size and
 * base, each combination with its own price and stock. The colour/size/price/
 * stock attributes on this model are computed aggregates kept for API
 * convenience; nothing writes to them.
 */
class Product extends Model
{
    use HasFactory, TracksActivity;

    public function activityTitle(): string
    {
        return $this->name ?: 'Product #'.$this->getKey();
    }

    /** The images gallery is bulky JSON — record that it changed, not the blob. */
    public function activityIgnored(): array
    {
        return ['images'];
    }

    protected $fillable = [
        'brand_id',
        'name',         // the product line, e.g. "BOYSEN Latex Colors"
        'description',
        // Colour is chosen by the CUSTOMER, not stocked. Variants of such a
        // product are cans of untinted base — see CUSTOM_COLOR.md.
        'is_custom_color',
        'images',       // ordered gallery; first entry is the cover
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'is_custom_color' => 'boolean',
        'images' => 'array',
    ];

    protected $appends = ['image', 'colors', 'size_volume', 'price', 'stock', 'is_low_stock', 'stock_status'];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    /**
     * A paint is often shelved under more than one heading — an enamel that
     * is also a wood coating belongs in both, or customers browsing either
     * one never find it.
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Ordered EXPLICITLY. The identity unique index is leftmost-prefixed on
     * product_id, so an unordered query gets answered from it and comes back
     * sorted by colour code — which put every uncoded shade first. What the
     * admin dragged into place is the order customers should see.
     */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeVariants()
    {
        return $this->variants()->where('is_archived', false);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class)->latest();
    }

    /**
     * Color codes pasted from manufacturer sites/PDFs often carry Unicode
     * dashes (‑ – —) that silently break search against an ASCII hyphen.
     */
    public static function normalizeColorCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = trim(str_replace(
            ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"],
            '-',
            $code
        ));

        return $normalized === '' ? null : $normalized;
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    /** Cover image = first of the gallery; keeps single-image consumers working. */
    public function getImageAttribute(): ?string
    {
        return $this->images[0] ?? null;
    }

    /**
     * The distinct colours this product is stocked in, in the order the
     * admin arranged the variants.
     *
     * A colour is identified by the PAIR (code, name) — plenty of shades have
     * a name and no manufacturer code, so keying on the code alone would fold
     * "White" and "Off-White" into one chip. `hex_code` is a screen preview
     * taken from the first variant of the colour; it identifies nothing.
     *
     * Empty for an uncoded product (thinners, tools) and for a custom-colour
     * one, where the colour is the customer's to choose.
     */
    public function getColorsAttribute(): array
    {
        return $this->availableVariants()
            ->filter(fn ($v) => $v->has_color)
            ->groupBy(fn ($v) => $v->color_key)
            ->map(function ($variants, $key) {
                $first = $variants->first();
                $stock = (int) $variants->sum('stock');

                return [
                    'key' => $key,
                    'color_code' => $first->color_code,
                    'color_name' => $first->color_name,
                    'hex_code' => $variants->firstWhere('hex_code', '!=', null)?->hex_code,
                    'label' => $first->color_label,
                    'stock' => $stock,
                    'stock_status' => $stock === 0
                        ? 'out_of_stock'
                        : ($variants->contains(fn ($v) => $v->is_low_stock) ? 'low_stock' : 'in_stock'),
                ];
            })
            ->values()
            ->all();
    }

    // -------------------------------------------------------
    // Aggregate accessors (computed from variants)
    // -------------------------------------------------------

    /**
     * Comma list of the DISTINCT active sizes, e.g. "1L, 4L, 16L".
     *
     * One size is one size however many shades it is stocked in. Plucking per
     * variant repeated the whole list once per colour, so a line sold in six
     * shades read "1L, 4L, 1L, 4L, ..." on the catalogue card. Matching is
     * case- and space-insensitive ("4L" and "4 l" are the same can) but the
     * first spelling is what prints. Order follows `sort_order`, so the admin
     * still controls it.
     */
    public function getSizeVolumeAttribute(): string
    {
        return $this->distinctSizes()->implode(', ');
    }

    /** The distinct active sizes, first spelling wins, in variant order. */
    public function distinctSizes(): Collection
    {
        return $this->availableVariants()
            ->pluck('size_volume')
            ->filter(fn ($size) => trim((string) $size) !== '')
            ->unique(fn ($size) => mb_strtolower(preg_replace('/\s+/', '', $size)))
            ->values();
    }

    /** Lowest active-variant price — a "from ₱…" display price. */
    public function getPriceAttribute(): float
    {
        return (float) ($this->availableVariants()->min('price') ?? 0);
    }

    /** Total units across active variants. */
    public function getStockAttribute(): int
    {
        return (int) $this->availableVariants()->sum('stock');
    }

    /** True when ANY active variant is at or below its threshold. */
    public function getIsLowStockAttribute(): bool
    {
        return $this->availableVariants()->contains(fn ($v) => $v->is_low_stock);
    }

    /** Worst-case status across active variants. */
    public function getStockStatusAttribute(): string
    {
        $variants = $this->availableVariants();

        if ($variants->isEmpty() || $variants->every(fn ($v) => $v->stock === 0)) {
            return 'out_of_stock';
        }

        if ($variants->contains(fn ($v) => $v->is_low_stock)) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    private function availableVariants()
    {
        return $this->variants->where('is_archived', false);
    }

    // -------------------------------------------------------
    // Scopes (variant-aware)
    // -------------------------------------------------------

    /** Products with at least one active variant needing attention. */
    public function scopeLowStock($query)
    {
        return $query->whereHas('variants', fn ($q) => $q
            ->where('is_archived', false)
            ->whereColumn('stock', '<=', 'low_stock_threshold'));
    }

    /** Products with at least one active variant fully sold out. */
    public function scopeOutOfStock($query)
    {
        return $query->whereHas('variants', fn ($q) => $q
            ->where('is_archived', false)
            ->where('stock', 0));
    }

    /** Products a customer can buy right now. */
    public function scopePurchasable($query)
    {
        return $query->where('is_archived', false)
            ->whereHas('variants', fn ($q) => $q
                ->where('is_archived', false)
                ->where('stock', '>', 0));
    }
}
