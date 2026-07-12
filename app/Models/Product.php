<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Product identity (name, brand, category, color, image).
 * Purchasable sizes live in ProductVariant — price, stock, and
 * thresholds are per variant. The stock/price/size_volume attributes
 * on this model are computed aggregates kept for API convenience.
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'description',
        'color_code',   // manufacturer color code, e.g. "888"
        'color_name',   // manufacturer color name, e.g. "Red"
        'hex_code',
        'images',       // ordered gallery; first entry is the cover
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'images'      => 'array',
    ];

    protected $appends = ['image', 'size_volume', 'price', 'stock', 'is_low_stock', 'stock_status'];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)->where('is_archived', false);
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

    public function setColorCodeAttribute(?string $value): void
    {
        $this->attributes['color_code'] = self::normalizeColorCode($value);
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    /** Cover image = first of the gallery; keeps single-image consumers working. */
    public function getImageAttribute(): ?string
    {
        return $this->images[0] ?? null;
    }

    // -------------------------------------------------------
    // Aggregate accessors (computed from variants)
    // -------------------------------------------------------

    /** Comma list of active sizes, e.g. "1L, 4L, 16L". */
    public function getSizeVolumeAttribute(): string
    {
        return $this->availableVariants()->pluck('size_volume')->implode(', ');
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
