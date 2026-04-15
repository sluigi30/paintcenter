<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'admin_id',
        'action_name',
        'quantity_changed',
        'notes',
    ];

    protected $casts = [
        'quantity_changed' => 'integer',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    /**
     * Returns a readable label for the action type.
     * Used for badges in the Filament table.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action_name) {
            'restock'       => 'Restocked',
            'deduct'        => 'Deducted',
            'order_deduct'  => 'Order Deducted',
            'adjustment'    => 'Adjusted',
            default         => ucfirst($this->action_name),
        };
    }

    /**
     * Returns a Filament badge color based on action type.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action_name) {
            'restock'       => 'success',
            'deduct'        => 'danger',
            'order_deduct'  => 'warning',
            'adjustment'    => 'info',
            default         => 'gray',
        };
    }

    /**
     * Shows quantity with + or - sign for clarity.
     * e.g. +50, -10
     */
    public function getFormattedQuantityAttribute(): string
    {
        return $this->quantity_changed > 0
            ? '+' . $this->quantity_changed
            : (string) $this->quantity_changed;
    }

    // -------------------------------------------------------
    // Static Helper — called when stock changes anywhere
    // -------------------------------------------------------

    /**
     * Creates a log entry and updates the product stock in one call.
     *
     * Usage:
     *   InventoryLog::record($product, 'restock', 50, 'Supplier delivery');
     *   InventoryLog::record($product, 'deduct', -5, 'Damaged items');
     */
    public static function record(
        Product $product,
        string $action,
        int $quantity,
        string $notes = ''
    ): self {
        // Update the product stock
        $product->increment('stock', $quantity);

        // Write the log entry
        return self::create([
            'product_id'       => $product->id,
            'admin_id'         => auth()->id(),
            'action_name'      => $action,
            'quantity_changed' => $quantity,
            'notes'            => $notes,
        ]);
    }
}