<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $lowStockCount  = Product::lowStock()->where('stock', '>', 0)->count();
        $outOfStock     = Product::outOfStock()->count();
        $pendingOrders  = Order::where('status', 'pending')->count();
        $todaySales     = Order::whereDate('created_at', today())
                            ->where('status', '!=', 'cancelled')
                            ->sum('total_amount');
        $totalCustomers = User::where('role', 'customer')->count();
        $totalProducts  = Product::count();

        return [
            // --- Today's Sales ---
            Stat::make('Today\'s Sales', '₱' . number_format($todaySales, 2))
                ->description('Total revenue for today')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart([
                    Order::whereDate('created_at', today()->subDays(6))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    Order::whereDate('created_at', today()->subDays(5))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    Order::whereDate('created_at', today()->subDays(4))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    Order::whereDate('created_at', today()->subDays(3))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    Order::whereDate('created_at', today()->subDays(2))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    Order::whereDate('created_at', today()->subDays(1))->where('status', '!=', 'cancelled')->sum('total_amount'),
                    $todaySales,
                ]),

            // --- Pending Orders ---
            Stat::make('Pending Orders', $pendingOrders)
                ->description('Orders waiting to be processed')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingOrders > 0 ? 'warning' : 'success'),

            // --- Total Customers ---
            Stat::make('Total Customers', number_format($totalCustomers))
                ->description('Registered customer accounts')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            // --- Total Products ---
            Stat::make('Total Products', number_format($totalProducts))
                ->description('Active products in catalog')
                ->descriptionIcon('heroicon-m-swatch')
                ->color('primary'),

            // --- Low Stock Alert ---
            Stat::make('Low Stock Products', $lowStockCount)
                ->description(
                    $lowStockCount > 0
                        ? "{$lowStockCount} product(s) need restocking soon"
                        : 'All products are well stocked'
                )
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'warning' : 'success'),

            // --- Out of Stock Alert ---
            Stat::make('Out of Stock', $outOfStock)
                ->description(
                    $outOfStock > 0
                        ? "{$outOfStock} product(s) are completely out of stock"
                        : 'No products are out of stock'
                )
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($outOfStock > 0 ? 'danger' : 'success'),
        ];
    }
}