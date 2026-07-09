<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'description',
        'size_volume',
        'hex_code',
        'price',
        'stock',
        'low_stock_threshold',
        'image',
        'is_archived',
    ];

    protected $appends = ['is_low_stock', 'stock_status'];

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

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class)->latest();
    }

    // -------------------------------------------------------
    // Accessors (computed properties)
    // -------------------------------------------------------

    /**
     * Returns true if stock is at or below the threshold.
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->stock <= $this->low_stock_threshold;
    }

    /**
     * Returns a readable stock status label.
     */
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

    // -------------------------------------------------------
    // Scopes (reusable query filters)
    // -------------------------------------------------------

    /**
     * Query only products that are low on stock or out of stock.
     * Usage: Product::lowStock()->get()
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'low_stock_threshold');
    }

    /**
     * Query only out-of-stock products.
     * Usage: Product::outOfStock()->get()
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('stock', 0);
    }
}