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
 * A variant is a (colour × size × base) combination. Price, stock, and the
 * low-stock threshold live HERE, not on Product: a 4L of Burnt Sienna and a
 * 1L of it are separate things on the shelf, and so are two shades of the
 * same size. Colour is '' on products sold in no particular colour (thinners,
 * tools) and on custom-colour lines, where the customer picks it at order time.
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
        'base_code',   // '' = no base distinction; 'P' pastel, 'M' medium, 'D' deep
        'price',
        'tint_fee',    // charged on top of price when this can is tinted
        'stock',
        'low_stock_threshold',
        'is_archived',
    ];

    protected $casts = [
        'price'       => 'float',
        'tint_fee'    => 'float',
        'stock'       => 'integer',
        'is_archived' => 'boolean',
    ];

    protected $appends = ['is_low_stock', 'stock_status', 'has_color', 'color_key', 'color_label'];

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
        return $this->color_code . '|' . $this->color_name;
    }

    /** "Burnt Sienna (B-1408)", or whichever half exists. '' if neither. */
    public function getColorLabelAttribute(): string
    {
        if ($this->color_name !== '' && $this->color_code !== '') {
            return "{$this->color_name} ({$this->color_code})";
        }

        return $this->color_name !== '' ? $this->color_name : $this->color_code;
    }

    /** "Boysen — Latex Colors · Burnt Sienna (4L)" for alerts and admin modals. */
    public function getDisplayNameAttribute(): string
    {
        $brand = $this->product?->brand?->brand_name;
        $color = $this->color_label;

        return trim(
            ($brand ? "{$brand} — " : '') .
            ($this->product?->name ?: 'Unknown product') .
            ($color !== '' ? " · {$color}" : '') .
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
