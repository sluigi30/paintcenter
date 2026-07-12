<?php

namespace App\Observers;

use App\Filament\Resources\InventoryResource;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class ProductVariantObserver
{
    /**
     * Stock severity levels, used to detect when a variant's
     * situation gets WORSE (never notify when it improves).
     */
    private const IN_STOCK     = 0;
    private const LOW_STOCK    = 1;
    private const OUT_OF_STOCK = 2;

    /**
     * Fires on every variant update, from any source:
     * InventoryLog::record(), API order deductions, Filament edits.
     */
    public function updated(ProductVariant $variant): void
    {
        if (! $variant->wasChanged(['stock', 'low_stock_threshold'])) {
            return;
        }

        $before = $this->severity(
            (int) $variant->getOriginal('stock'),
            (int) $variant->getOriginal('low_stock_threshold')
        );

        $after = $this->severity(
            (int) $variant->stock,
            (int) $variant->low_stock_threshold
        );

        // Only alert on downward transitions (in stock → low → out).
        // Repeated sales while already low won't re-notify — the alert
        // fires once, exactly when the threshold is crossed.
        if ($after <= $before) {
            return;
        }

        $after === self::OUT_OF_STOCK
            ? $this->notifyOutOfStock($variant)
            : $this->notifyLowStock($variant);
    }

    private function severity(int $stock, int $threshold): int
    {
        if ($stock <= 0) {
            return self::OUT_OF_STOCK;
        }

        if ($stock <= $threshold) {
            return self::LOW_STOCK;
        }

        return self::IN_STOCK;
    }

    private function notifyLowStock(ProductVariant $variant): void
    {
        $this->sendToAdmins(
            Notification::make()
                ->warning()
                ->title('Low Stock Alert')
                ->icon('heroicon-o-exclamation-triangle')
                ->body(
                    "<strong>{$variant->display_name}</strong> has fallen to " .
                    "<strong>{$variant->stock}</strong> " . \Illuminate\Support\Str::plural('unit', $variant->stock) .
                    " — at or below its reorder point of {$variant->low_stock_threshold}."
                )
                ->actions($this->notificationActions($variant))
        );
    }

    private function notifyOutOfStock(ProductVariant $variant): void
    {
        $this->sendToAdmins(
            Notification::make()
                ->danger()
                ->title('Out of Stock')
                ->icon('heroicon-o-x-circle')
                ->body(
                    "<strong>{$variant->display_name}</strong> has sold out and is " .
                    'unavailable to customers until restocked.'
                )
                ->actions($this->notificationActions($variant))
        );
    }

    /**
     * Deliver synchronously (notifyNow) instead of Filament's
     * sendToDatabase(), which queues the notification — alerts
     * must not silently wait for a queue worker to be running.
     */
    private function sendToAdmins(Notification $notification): void
    {
        foreach ($this->admins() as $admin) {
            $admin->notifyNow($notification->toDatabase());
        }
    }

    private function notificationActions(ProductVariant $variant): array
    {
        return [
            // Lands on Inventory with the product pre-searched, one
            // click from the audited Adjust Stock action. `search`
            // is Filament's URL alias for tableSearch.
            Action::make('restock')
                ->label('Restock')
                ->button()
                ->url(InventoryResource::getUrl('index', [
                    'search' => $variant->product?->description,
                ]))
                ->markAsRead(),
        ];
    }

    /**
     * All active admins and super admins receive the alert.
     */
    private function admins(): Collection
    {
        return User::whereIn('role', ['admin', 'super_admin'])
            ->where('is_archived', false)
            ->get();
    }
}
