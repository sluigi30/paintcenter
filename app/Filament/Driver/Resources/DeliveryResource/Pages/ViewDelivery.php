<?php

namespace App\Filament\Driver\Resources\DeliveryResource\Pages;

use App\Filament\Driver\Resources\DeliveryResource;
use App\Models\Order;
use Filament\Resources\Pages\ViewRecord;

/**
 * One delivery, and the one or two buttons that apply to it right now.
 *
 * The actions are the resource's own static definitions, so the detail screen
 * and the list row cannot come to disagree about what a driver may do — the
 * same reason OrderResource shares its Advance action between the table and the
 * edit header.
 */
class ViewDelivery extends ViewRecord
{
    protected static string $resource = DeliveryResource::class;

    /** Named by when it was placed, never by id. */
    public function getTitle(): string
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return 'Order placed ' . $record->created_at->format('M j, Y \a\t g:i A');
    }

    public function getSubheading(): ?string
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return Order::statusLabel($record->status);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeliveryResource::pickUpAction(),
            DeliveryResource::deliverAction(),
            DeliveryResource::failAction(),
        ];
    }
}
