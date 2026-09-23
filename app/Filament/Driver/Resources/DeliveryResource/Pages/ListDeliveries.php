<?php

namespace App\Filament\Driver\Resources\DeliveryResource\Pages;

use App\Filament\Driver\Resources\DeliveryResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The driver's work list, in the order the day happens: what to collect from
 * the store, what is already in the van, and what is done.
 *
 * Badge counts are computed from the resource's own scoped query, never from a
 * bare model count — a driver must not be able to learn how many deliveries
 * exist in total from a number on their own screen.
 */
class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    protected static ?string $title = 'My Deliveries';

    public function getTabs(): array
    {
        return [
            'to_pick_up' => Tab::make('To Pick Up')
                ->icon('heroicon-o-archive-box')
                ->query(fn (Builder $query) => $query->where('status', 'processing'))
                ->badge($this->countFor('processing'))
                ->badgeColor('primary'),

            'out' => Tab::make('Out for Delivery')
                ->icon('heroicon-o-truck')
                ->query(fn (Builder $query) => $query->where('status', 'shipped'))
                ->badge($this->countFor('shipped'))
                ->badgeColor('warning'),

            'history' => Tab::make('History')
                ->icon('heroicon-o-check-circle')
                ->query(fn (Builder $query) => $query->whereIn('status', ['completed', 'cancelled']))
                // No badge. A running total of everything ever delivered is a
                // number that only grows and tells the driver nothing about
                // today; the two work tabs are the ones worth counting.
                ->modifyQueryUsing(fn (Builder $query) => $query->reorder('created_at', 'desc')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'to_pick_up';
    }

    protected function countFor(string $status): int
    {
        return DeliveryResource::getEloquentQuery()->where('status', $status)->count();
    }
}
