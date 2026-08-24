<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
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

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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
     * Creates a log entry and updates the VARIANT stock in one call.
     * Stock lives per size — every adjustment names the exact variant.
     *
     * Usage:
     *   InventoryLog::record($variant, 'restock', 50, 'Supplier delivery');
     *   InventoryLog::record($variant, 'deduct', -5, 'Damaged items');
     */
    public static function record(
        ProductVariant $variant,
        string $action,
        int $quantity,
        string $notes = ''
    ): self {
        // Update the variant stock
        $variant->increment('stock', $quantity);

        // Write the log entry
        $log = self::create([
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'admin_id'           => auth()->id(),
            'action_name'        => $action,
            'quantity_changed'   => $quantity,
            'notes'              => $notes,
        ]);

        // Mirror the stock move into the unified admin audit trail so a
        // super admin sees inventory management alongside every other
        // action. Only records when an admin is authenticated.
        ActivityLog::log(
            event: 'inventory',
            subject: $variant,
            description: sprintf(
                '%s %s on %s — now %d in stock%s',
                self::actionVerb($action),
                ($quantity >= 0 ? '+' : '') . $quantity . ' ' . \Illuminate\Support\Str::plural('unit', abs($quantity)),
                $variant->display_name,
                $variant->stock,
                $notes ? " ({$notes})" : ''
            ),
            properties: [
                'action'    => $action,
                'quantity'  => $quantity,
                'new_stock' => $variant->stock,
                'notes'     => $notes,
            ],
        );

        return $log;
    }

    /**
     * Verb used in the activity-feed sentence for each stock action.
     */
    protected static function actionVerb(string $action): string
    {
        return match ($action) {
            'restock'      => 'Restocked',
            'deduct'       => 'Deducted',
            'order_deduct' => 'Order deduction',
            'adjustment'   => 'Adjusted',
            default        => ucfirst($action),
        };
    }
}