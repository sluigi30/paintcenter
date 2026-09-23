<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * The same two status actions the table row carries. An admin working a
     * single order should not have to go back to the list to move it along —
     * the form no longer has a status field to set.
     *
     * There is deliberately NO delete. An order is a financial record: both
     * `order_items` and `payments` are cascadeOnDelete, so deleting one takes
     * the line items and the payment with it, silently rewrites every revenue
     * figure the Reports page has ever shown, and — with no `deleted` hook on
     * OrderObserver — never returns the stock it took. An order that should not
     * have happened is CANCELLED, which records a reason, restores stock and
     * tells the customer. Same archive-over-delete rule the rest of the panel
     * follows; orders simply reach it through cancellation.
     */
    protected function getHeaderActions(): array
    {
        return [
            OrderResource::assignDriverAction(),
            OrderResource::advanceStatusAction(),
            OrderResource::revertStatusAction(),
        ];
    }
}
