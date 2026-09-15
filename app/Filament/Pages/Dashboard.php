<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\StatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string $routePath = 'dashboard';
    protected static ?string $title = 'Dashboard';

    public function getWidgets(): array
    {
        // This list REPLACES Filament's "every discovered widget" default, so
        // anything left out renders nowhere while still existing. LowStockWidget
        // was missing and silently invisible. It hides itself when no variant is
        // low (canView), so listing it costs nothing on a well-stocked day.
        return [
            StatsOverview::class,
            LowStockWidget::class,
        ];
    }

    public function getColumns(): int | array
    {
        return 3;
    }
}