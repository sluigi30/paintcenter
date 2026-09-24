<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Everything still moving through the shop — the day-to-day work list. */
    private const ACTIVE_STATUSES = ['pending', 'processing', 'shipped', 'ready_for_pickup'];

    /**
     * Orders are split into three views rather than one long mixed list:
     * what still needs work, what is done, and what fell through. Same table
     * definition underneath — only the query differs.
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('To Process')
                ->icon('heroicon-o-clock')
                ->query(fn (Builder $query) => $query->whereIn('status', self::ACTIVE_STATUSES))
                ->badge(Order::whereIn('status', self::ACTIVE_STATUSES)->count())
                ->badgeColor('warning'),

            'completed' => Tab::make('Completed')
                ->icon('heroicon-o-check-circle')
                ->query(fn (Builder $query) => $query->where('status', 'completed'))
                ->badge(Order::where('status', 'completed')->count())
                ->badgeColor('success'),

            'cancelled' => Tab::make('Cancelled')
                ->icon('heroicon-o-x-circle')
                ->query(fn (Builder $query) => $query->where('status', 'cancelled'))
                ->badge(Order::where('status', 'cancelled')->count())
                ->badgeColor('danger'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }
}
