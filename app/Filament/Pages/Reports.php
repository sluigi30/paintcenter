<?php

namespace App\Filament\Pages;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class Reports extends Page
{
    protected string $view = 'filament.pages.reports';
    protected static ?string $title = 'Reports';
    protected static ?string $navigationLabel = 'Reports';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    // ── Date Range ─────────────────────────────────────────
    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    // ── Comparison Range ───────────────────────────────────
    #[Url]
    public string $compareFrom = '';

    #[Url]
    public string $compareTo = '';

    #[Url]
    public bool $compareEnabled = false;

    // ── Quick Preset ───────────────────────────────────────
    #[Url]
    public string $preset = '30';

    // ── KPI Cards ──────────────────────────────────────────
    public float $totalRevenue      = 0;
    public float $compareRevenue    = 0;
    public int   $totalOrders       = 0;
    public int   $compareOrders     = 0;
    public float $avgOrderValue     = 0;
    public float $compareAvgOrder   = 0;
    public int   $totalCustomers    = 0;
    public int   $newCustomers      = 0;
    public int   $compareNewCustomers = 0;
    public int   $pendingOrders     = 0;

    // ── Charts ─────────────────────────────────────────────
    public array $salesChart        = [];
    public array $compareChart      = [];
    public array $ordersByStatus    = [];
    public array $revenueByType     = [];

    // ── Tables ─────────────────────────────────────────────
    public array $topProducts       = [];
    public array $topCategories     = [];
    public array $recentOrders      = [];

    // ── Inventory ──────────────────────────────────────────
    public array $inventoryStats    = [];
    public array $recentLogs        = [];

    public function mount(): void
    {
        if (empty($this->dateFrom)) {
            $this->dateFrom = now()->subDays(29)->format('Y-m-d');
        }
        if (empty($this->dateTo)) {
            $this->dateTo = now()->format('Y-m-d');
        }
        if (empty($this->compareFrom) || empty($this->compareTo)) {
            $this->autoSetComparePeriod();
        }
        $this->loadReports();
    }

    public function updatedDateFrom(): void  { $this->preset = 'custom'; $this->loadReports(); }
    public function updatedDateTo(): void    { $this->preset = 'custom'; $this->loadReports(); }
    public function updatedCompareFrom(): void  { $this->loadReports(); }
    public function updatedCompareTo(): void    { $this->loadReports(); }
    public function updatedCompareEnabled(): void { $this->loadReports(); }

    public function applyPreset(string $p): void
    {
        $this->preset = $p;
        match ($p) {
            '7'   => [$this->dateFrom, $this->dateTo] = [now()->subDays(6)->format('Y-m-d'),  now()->format('Y-m-d')],
            '30'  => [$this->dateFrom, $this->dateTo] = [now()->subDays(29)->format('Y-m-d'), now()->format('Y-m-d')],
            '90'  => [$this->dateFrom, $this->dateTo] = [now()->subDays(89)->format('Y-m-d'), now()->format('Y-m-d')],
            '365' => [$this->dateFrom, $this->dateTo] = [now()->startOfYear()->format('Y-m-d'), now()->format('Y-m-d')],
            'mtd' => [$this->dateFrom, $this->dateTo] = [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            'lm'  => [$this->dateFrom, $this->dateTo] = [
                now()->subMonth()->startOfMonth()->format('Y-m-d'),
                now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            default => null,
        };
        $this->autoSetComparePeriod();
        $this->loadReports();
    }

    private function autoSetComparePeriod(): void
    {
        $from = Carbon::parse($this->dateFrom);
        $to   = Carbon::parse($this->dateTo);
        $days = $from->diffInDays($to) + 1;
        $this->compareTo   = $from->copy()->subDay()->format('Y-m-d');
        $this->compareFrom = $from->copy()->subDays($days)->format('Y-m-d');
    }

    private function mainRange(): array
    {
        return [
            Carbon::parse($this->dateFrom)->startOfDay(),
            Carbon::parse($this->dateTo)->endOfDay(),
        ];
    }

    private function compareRange(): array
    {
        return [
            Carbon::parse($this->compareFrom)->startOfDay(),
            Carbon::parse($this->compareTo)->endOfDay(),
        ];
    }

    public function loadReports(): void
    {
        [$from, $to]   = $this->mainRange();
        [$cFrom, $cTo] = $this->compareRange();

        $days = $from->diffInDays($to) + 1;

        // ── Cross-database date grouping ───────────────────
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $dateFmt = $days <= 31
                ? "strftime('%Y-%m-%d', created_at)"
                : "strftime('%Y-%m', created_at)";
        } else {
            $dateFmt = $days <= 31
                ? "DATE_FORMAT(created_at, '%Y-%m-%d')"
                : "DATE_FORMAT(created_at, '%Y-%m')";
        }

        // ── KPIs ───────────────────────────────────────────
        $this->totalRevenue = Order::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')->sum('total_amount');

        $this->compareRevenue = Order::whereBetween('created_at', [$cFrom, $cTo])
            ->where('status', '!=', 'cancelled')->sum('total_amount');

        $this->totalOrders = Order::whereBetween('created_at', [$from, $to])->count();
        $this->compareOrders = Order::whereBetween('created_at', [$cFrom, $cTo])->count();

        $this->avgOrderValue = $this->totalOrders > 0
            ? round($this->totalRevenue / $this->totalOrders, 2) : 0;

        $this->compareAvgOrder = $this->compareOrders > 0
            ? round($this->compareRevenue / $this->compareOrders, 2) : 0;

        $this->totalCustomers = User::where('role', 'customer')->count();

        $this->newCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$from, $to])->count();

        $this->compareNewCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$cFrom, $cTo])->count();

        $this->pendingOrders = Order::where('status', 'pending')->count();

        // ── Sales Chart ────────────────────────────────────
        $this->salesChart = Order::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw("{$dateFmt} as label, SUM(total_amount) as revenue, COUNT(*) as orders")
            ->groupByRaw($dateFmt)
            ->orderByRaw($dateFmt)
            ->get()
            ->map(fn ($row) => [
                'label'   => $row->label,
                'revenue' => (float) $row->revenue,
                'orders'  => (int) $row->orders,
            ])->toArray();

        $this->compareChart = $this->compareEnabled
            ? Order::whereBetween('created_at', [$cFrom, $cTo])
                ->where('status', '!=', 'cancelled')
                ->selectRaw("{$dateFmt} as label, SUM(total_amount) as revenue, COUNT(*) as orders")
                ->groupByRaw($dateFmt)
                ->orderByRaw($dateFmt)
                ->get()
                ->map(fn ($row) => [
                    'label'   => $row->label,
                    'revenue' => (float) $row->revenue,
                    'orders'  => (int) $row->orders,
                ])->toArray()
            : [];

        // ── Orders by Status ───────────────────────────────
        $this->ordersByStatus = Order::whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count'  => (int) $row->count,
                'total'  => (float) $row->total,
            ])->toArray();

        // ── Revenue by Order Type ──────────────────────────
        $this->revenueByType = Order::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('order_type, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('order_type')->get()
            ->map(fn ($row) => [
                'type'  => $row->order_type,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])->toArray();

        // ── Top Products ───────────────────────────────────
        $this->topProducts = OrderItem::with(['product.brand'])
            ->whereHas('order', fn ($q) => $q
                ->whereBetween('created_at', [$from, $to])
                ->where('status', '!=', 'cancelled'))
            ->selectRaw('product_id, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue')
            ->groupBy('product_id')->orderByDesc('total_revenue')->limit(8)->get()
            ->map(fn ($item) => [
                'name'          => \Str::limit($item->product?->description ?? 'Unknown', 45),
                'brand'         => $item->product?->brand?->brand_name ?? '—',
                'hex_code'      => $item->product?->hex_code ?? 'CCCCCC',
                'total_qty'     => (int) $item->total_qty,
                'total_revenue' => (float) $item->total_revenue,
            ])->toArray();

        // ── Top Categories ─────────────────────────────────
        $this->topCategories = OrderItem::with(['product.category'])
            ->whereHas('order', fn ($q) => $q
                ->whereBetween('created_at', [$from, $to])
                ->where('status', '!=', 'cancelled'))
            ->selectRaw('product_id, SUM(subtotal) as total_revenue, SUM(quantity) as total_qty')
            ->groupBy('product_id')->get()
            ->groupBy(fn ($item) => $item->product?->category?->category_name ?? 'Uncategorized')
            ->map(fn ($items, $cat) => [
                'category'      => $cat,
                'total_revenue' => round($items->sum('total_revenue'), 2),
                'total_qty'     => $items->sum('total_qty'),
            ])->sortByDesc('total_revenue')->take(5)->values()->toArray();

        // ── Recent Orders ──────────────────────────────────
        $this->recentOrders = Order::with(['user', 'payment'])
            ->latest()->limit(10)->get()
            ->map(fn ($order) => [
                'id'       => $order->id,
                'customer' => trim(($order->user?->first_name . ' ' . $order->user?->last_name)) ?: 'Guest',
                'status'   => $order->status,
                'type'     => $order->order_type,
                'total'    => (float) $order->total_amount,
                'payment'  => $order->payment?->payment_status ?? 'pending',
                'date'     => $order->created_at->format('M d, Y'),
            ])->toArray();

        // ── Inventory Stats ────────────────────────────────
        $this->inventoryStats = [
            'total_products' => Product::count(),
            'total_stock'    => Product::sum('stock'),
            'low_stock'      => Product::lowStock()->where('stock', '>', 0)->count(),
            'out_of_stock'   => Product::outOfStock()->count(),
            'stock_value'    => Product::selectRaw('SUM(stock * price) as value')->value('value') ?? 0,
        ];

        // ── Recent Inventory Logs ──────────────────────────
        $this->recentLogs = InventoryLog::with(['product', 'admin'])
            ->latest()->limit(6)->get()
            ->map(fn ($log) => [
                'product' => $log->product?->description ?? 'Unknown',
                'action'  => $log->action_name,
                'qty'     => $log->quantity_changed,
                'admin'   => $log->admin?->first_name ?? 'System',
                'date'    => $log->created_at->format('M d, h:i A'),
            ])->toArray();

        // ── Notify JS to redraw charts ─────────────────────
        $this->dispatch('rpt:data',
            sales:          $this->salesChart,
            compare:        $this->compareChart,
            status:         $this->ordersByStatus,
            compareEnabled: $this->compareEnabled,
        );
    }

    // ── Helpers ────────────────────────────────────────────
    public function pctChange(float $current, float $previous): float
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function revenueChange(): float  { return $this->pctChange($this->totalRevenue,  $this->compareRevenue); }
    public function ordersChange(): float   { return $this->pctChange($this->totalOrders,   $this->compareOrders); }
    public function avgOrderChange(): float { return $this->pctChange($this->avgOrderValue, $this->compareAvgOrder); }
    public function newCustChange(): float  { return $this->pctChange($this->newCustomers,  $this->compareNewCustomers); }

    public function dayCount(): int
    {
        return Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) + 1;
    }

    public function periodLabel(): string
    {
        return Carbon::parse($this->dateFrom)->format('M d, Y')
            . ' – '
            . Carbon::parse($this->dateTo)->format('M d, Y');
    }

    public function comparePeriodLabel(): string
    {
        return Carbon::parse($this->compareFrom)->format('M d, Y')
            . ' – '
            . Carbon::parse($this->compareTo)->format('M d, Y');
    }
}
