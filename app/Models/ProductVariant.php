<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use App\Observers\ProductVariantObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One purchasable size of a product — "Anzahl Urethane (4L)".
 * Price, stock, and the low-stock threshold live HERE, not on Product.
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

    protected $appends = ['is_low_stock', 'stock_status'];

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

    /** "Anzahl — Urethane Paint (4L)" for alerts and admin modals. */
    public function getDisplayNameAttribute(): string
    {
        $brand = $this->product?->brand?->brand_name;

        return trim(
            ($brand ? "{$brand} — " : '') .
            ($this->product?->description ?? 'Unknown product') .
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
