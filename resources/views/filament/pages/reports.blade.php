<x-filament-panels::page>
<style>
/* ── Layout ──────────────────────────────────────────────── */
.rpt-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.rpt-grid-3{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px}
.rpt-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.rpt-grid-side{display:flex;flex-direction:column;gap:16px}

/* ── Cards ───────────────────────────────────────────────── */
.rpt-card{background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);border-radius:12px;padding:20px}
.rpt-card h3{font-size:12px;font-weight:600;color:var(--color-text-tertiary);margin:0 0 16px 0;text-transform:uppercase;letter-spacing:.07em}

/* ── KPIs ────────────────────────────────────────────────── */
.rpt-kpi-label{font-size:11px;font-weight:500;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.06em;margin:0 0 6px 0}
.rpt-kpi-value{font-size:26px;font-weight:700;color:var(--color-text-primary);margin:0 0 4px 0;line-height:1}
.rpt-kpi-change{font-size:11px;margin:0 0 4px}
.rpt-kpi-compare{font-size:11px;color:var(--color-text-tertiary);margin:0}
.rpt-up{color:#22c55e}.rpt-down{color:#ef4444}.rpt-muted{color:var(--color-text-tertiary)}
.rpt-neutral{color:#94a3b8}

/* ── Date Toolbar ────────────────────────────────────────── */
.rpt-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:20px;
    background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);
    border-radius:12px;padding:14px 16px}
.rpt-toolbar-label{font-size:11px;font-weight:600;color:var(--color-text-tertiary);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.rpt-presets{display:flex;gap:6px;flex-wrap:wrap}
.rpt-btn{padding:5px 12px;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;
    border:1px solid var(--color-border-secondary);background:transparent;
    color:var(--color-text-secondary);transition:all .15s}
.rpt-btn:hover{background:var(--color-background-tertiary)}
.rpt-btn.active{background:#f97316;color:#fff;border-color:#f97316}
.rpt-separator{width:1px;height:28px;background:var(--color-border-tertiary);flex-shrink:0}

/* ── Date Inputs ─────────────────────────────────────────── */
.rpt-date-group{display:flex;align-items:center;gap:8px}
.rpt-date-label{font-size:11px;color:var(--color-text-tertiary);white-space:nowrap}
.rpt-date-input{padding:5px 10px;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;
    border:1px solid var(--color-border-secondary);background:var(--color-background-tertiary);
    color:var(--color-text-primary);outline:none;transition:border-color .15s}
.rpt-date-input:focus{border-color:#f97316}

/* ── Compare Toggle ──────────────────────────────────────── */
.rpt-compare-row{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px;
    padding-top:10px;border-top:1px solid var(--color-border-tertiary)}
.rpt-toggle-wrap{display:flex;align-items:center;gap:8px;cursor:pointer}
.rpt-toggle{width:34px;height:18px;border-radius:9px;background:var(--color-border-secondary);
    position:relative;transition:background .2s;flex-shrink:0;cursor:pointer;border:none;padding:0}
.rpt-toggle.on{background:#f97316}
.rpt-toggle::after{content:'';position:absolute;top:2px;left:2px;width:14px;height:14px;
    border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.rpt-toggle.on::after{transform:translateX(16px)}
.rpt-toggle-label{font-size:12px;font-weight:500;color:var(--color-text-secondary)}
.rpt-compare-fields{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rpt-compare-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;
    border-radius:5px;background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.2);
    font-size:11px;color:#f97316;font-weight:500}

/* ── Misc ────────────────────────────────────────────────── */
.rpt-divider{border:none;border-top:1px solid var(--color-border-tertiary);margin:12px 0}
.rpt-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--color-border-tertiary)}
.rpt-row:last-child{border-bottom:none}
.rpt-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.rpt-bar-wrap{height:6px;background:var(--color-border-tertiary);border-radius:4px;overflow:hidden;margin-top:4px}
.rpt-bar-fill{height:100%;border-radius:4px}
.rpt-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.rpt-stat-box{border-radius:10px;padding:12px;text-align:center}
.rpt-stat-box p:first-child{font-size:22px;font-weight:700;margin:0 0 2px 0}
.rpt-stat-box p:last-child{font-size:11px;margin:0;color:var(--color-text-tertiary)}
.rpt-log-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:4px 0}
.rpt-product-name{font-size:13px;font-weight:500;color:var(--color-text-primary);margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px}
.rpt-product-meta{font-size:11px;color:var(--color-text-tertiary);margin:2px 0 4px}
.rpt-overflow-fix{min-width:0;overflow:hidden}

/* ── Compare bar on chart ─────────────────────────────────── */
.rpt-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.rpt-legend-item{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--color-text-secondary)}
.rpt-legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

@media(max-width:900px){.rpt-grid-4{grid-template-columns:1fr 1fr}.rpt-grid-3,.rpt-grid-2{grid-template-columns:1fr}}
@media(max-width:500px){.rpt-grid-4{grid-template-columns:1fr}}
</style>

{{-- ══════════════════════════════════════════════════════════
     DATE TOOLBAR
════════════════════════════════════════════════════════════ --}}
<div class="rpt-toolbar">
    {{-- Quick presets --}}
    <span class="rpt-toolbar-label">Quick</span>
    <div class="rpt-presets">
        @foreach(['7'=>'7D','30'=>'30D','90'=>'90D','mtd'=>'MTD','lm'=>'Last Mo.','365'=>'This Year'] as $val=>$label)
            <button class="rpt-btn {{ $preset===$val?'active':'' }}" wire:click="applyPreset('{{ $val }}')">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rpt-separator"></div>

    {{-- Custom date range --}}
    <span class="rpt-toolbar-label">Range</span>
    <div class="rpt-date-group">
        <input type="date" class="rpt-date-input" wire:model.live="dateFrom" max="{{ $dateTo }}">
        <span class="rpt-date-label">→</span>
        <input type="date" class="rpt-date-input" wire:model.live="dateTo" min="{{ $dateFrom }}" max="{{ now()->format('Y-m-d') }}">
    </div>

    <span style="margin-left:auto;font-size:11px;color:var(--color-text-tertiary)">
        {{ $this->dayCount() }} days · Updated {{ now()->format('M d, g:i A') }}
    </span>

    {{-- Compare row --}}
    <div class="rpt-compare-row" style="width:100%">
        <label class="rpt-toggle-wrap" wire:click="$toggle('compareEnabled')">
            <button type="button" class="rpt-toggle {{ $compareEnabled?'on':'' }}"></button>
            <span class="rpt-toggle-label">Compare to another period</span>
        </label>

        @if($compareEnabled)
        <div class="rpt-compare-fields">
            <span class="rpt-compare-tag">⬛ Compare</span>
            <input type="date" class="rpt-date-input" wire:model.live="compareFrom" max="{{ $compareTo }}" style="border-color:rgba(96,165,250,0.5)">
            <span class="rpt-date-label">→</span>
            <input type="date" class="rpt-date-input" wire:model.live="compareTo" min="{{ $compareFrom }}" style="border-color:rgba(96,165,250,0.5)">
            <span style="font-size:11px;color:var(--color-text-tertiary)">
                ({{ \Carbon\Carbon::parse($compareFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($compareTo)->format('M d, Y') }})
            </span>
        </div>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     KPI CARDS
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-4">

    {{-- Revenue --}}
    <div class="rpt-card">
        <p class="rpt-kpi-label">Total Revenue</p>
        <p class="rpt-kpi-value">₱{{ number_format($totalRevenue,2) }}</p>
        @php $rc=$this->revenueChange(); @endphp
        <p class="rpt-kpi-change {{ $rc>0?'rpt-up':($rc<0?'rpt-down':'rpt-neutral') }}">
            {{ $rc>0?'▲':($rc<0?'▼':'—') }} {{ abs($rc) }}% vs compare
        </p>
        @if($compareEnabled)
        <p class="rpt-kpi-compare">Compare: ₱{{ number_format($compareRevenue,2) }}</p>
        @endif
    </div>

    {{-- Orders --}}
    <div class="rpt-card">
        <p class="rpt-kpi-label">Total Orders</p>
        <p class="rpt-kpi-value">{{ number_format($totalOrders) }}</p>
        @php $oc=$this->ordersChange(); @endphp
        <p class="rpt-kpi-change {{ $oc>0?'rpt-up':($oc<0?'rpt-down':'rpt-neutral') }}">
            {{ $oc>0?'▲':($oc<0?'▼':'—') }} {{ abs($oc) }}% vs compare
        </p>
        @if($compareEnabled)
        <p class="rpt-kpi-compare">Compare: {{ number_format($compareOrders) }} orders</p>
        @endif
    </div>

    {{-- AOV --}}
    <div class="rpt-card">
        <p class="rpt-kpi-label">Avg Order Value</p>
        <p class="rpt-kpi-value">₱{{ number_format($avgOrderValue,2) }}</p>
        @php $ac=$this->avgOrderChange(); @endphp
        <p class="rpt-kpi-change {{ $ac>0?'rpt-up':($ac<0?'rpt-down':'rpt-neutral') }}">
            {{ $ac>0?'▲':($ac<0?'▼':'—') }} {{ abs($ac) }}% vs compare
        </p>
        @if($compareEnabled)
        <p class="rpt-kpi-compare">Compare: ₱{{ number_format($compareAvgOrder,2) }}</p>
        @endif
    </div>

    {{-- Customers --}}
    <div class="rpt-card">
        <p class="rpt-kpi-label">New Customers</p>
        <p class="rpt-kpi-value">{{ number_format($newCustomers) }}</p>
        @php $nc=$this->newCustChange(); @endphp
        <p class="rpt-kpi-change {{ $nc>0?'rpt-up':($nc<0?'rpt-down':'rpt-neutral') }}">
            {{ $nc>0?'▲':($nc<0?'▼':'—') }} {{ abs($nc) }}% vs compare
        </p>
        @if($compareEnabled)
        <p class="rpt-kpi-compare">Compare: {{ number_format($compareNewCustomers) }} new</p>
        @else
        <p class="rpt-kpi-compare">Total customers: {{ number_format($totalCustomers) }}</p>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     SALES CHART + STATUS DONUT
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-3">
    <div class="rpt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
            <div>
                <h3 style="margin:0 0 4px">Revenue Over Time</h3>
                <span style="font-size:11px;color:var(--color-text-tertiary)">
                    {{ \Carbon\Carbon::parse($dateFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }}
                </span>
            </div>
        </div>

        {{-- Legend --}}
        <div class="rpt-legend">
            <div class="rpt-legend-item"><div class="rpt-legend-dot" style="background:#f97316"></div> Revenue (current)</div>
            <div class="rpt-legend-item"><div class="rpt-legend-dot" style="background:#60a5fa;border-radius:2px;height:3px;width:16px;border-radius:0"></div> Orders (current)</div>
            @if($compareEnabled)
            <div class="rpt-legend-item"><div class="rpt-legend-dot" style="background:#a78bfa"></div> Revenue (compare)</div>
            @endif
        </div>

        <canvas id="salesChart" style="max-height:230px"></canvas>
    </div>
    <div class="rpt-card">
        <h3>Orders by Status</h3>
        <div style="display:flex;justify-content:center;margin-bottom:16px">
            <canvas id="statusChart" style="max-width:180px;max-height:180px"></canvas>
        </div>
        @foreach($ordersByStatus as $row)
        <div class="rpt-row">
            <span style="font-size:12px;color:var(--color-text-secondary);text-transform:capitalize">{{ str_replace('_',' ',$row['status']) }}</span>
            <span style="font-size:12px;font-weight:600;color:var(--color-text-primary)">{{ $row['count'] }}</span>
        </div>
        @endforeach
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     TOP PRODUCTS + SIDE PANELS
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-3">
    <div class="rpt-card">
        <h3>Top Selling Products</h3>
        @forelse($topProducts as $product)
        @php $maxRev=$topProducts[0]['total_revenue']??1; @endphp
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--color-border-tertiary)">
            <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;border:2px solid var(--color-border-secondary);background:#{{ ltrim($product['hex_code'],'#') }}"></div>
            <div style="flex:1;min-width:0;overflow:hidden">
                <p class="rpt-product-name">{{ Str::limit($product['name'], 40) }}</p>
                <p class="rpt-product-meta">{{ $product['brand'] }} · {{ $product['total_qty'] }} units</p>
                <div class="rpt-bar-wrap"><div class="rpt-bar-fill" style="width:{{ min(100,($product['total_revenue']/$maxRev)*100) }}%;background:#f97316"></div></div>
            </div>
            <span style="font-size:13px;font-weight:600;color:var(--color-text-primary);white-space:nowrap">₱{{ number_format($product['total_revenue'],0) }}</span>
        </div>
        @empty
        <p style="font-size:13px;color:var(--color-text-tertiary);text-align:center;padding:20px 0">No sales data for this period.</p>
        @endforelse
    </div>

    <div class="rpt-grid-side">
        <div class="rpt-card">
            <h3>Revenue by Category</h3>
            @forelse($topCategories as $cat)
            <div class="rpt-row">
                <span style="font-size:12px;color:var(--color-text-secondary)">{{ $cat['category'] }}</span>
                <span style="font-size:12px;font-weight:600;color:var(--color-text-primary)">₱{{ number_format($cat['total_revenue'],0) }}</span>
            </div>
            @empty
            <p style="font-size:12px;color:var(--color-text-tertiary)">No data yet.</p>
            @endforelse
        </div>

        <div class="rpt-card">
            <h3>Delivery vs Pickup</h3>
            @php $typeTotal=collect($revenueByType)->sum('count')?:1; @endphp
            @foreach($revenueByType as $type)
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                    <span style="color:var(--color-text-secondary);text-transform:capitalize">{{ $type['type'] }}</span>
                    <span style="font-weight:600;color:var(--color-text-primary)">{{ $type['count'] }} orders</span>
                </div>
                <div class="rpt-bar-wrap"><div class="rpt-bar-fill" style="width:{{ ($type['count']/$typeTotal)*100 }}%;background:{{ $type['type']==='delivery'?'#60a5fa':'#4ade80' }}"></div></div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     RECENT ORDERS + INVENTORY
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-2">
    <div class="rpt-card">
        <h3>Recent Orders</h3>
        @foreach($recentOrders as $order)
        @php $badge=match($order['status']){'completed'=>['#dcfce7','#166534'],'pending'=>['#fef9c3','#854d0e'],'cancelled'=>['#fee2e2','#991b1b'],default=>['#dbeafe','#1e40af']}; @endphp
        <div class="rpt-row">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:11px;color:var(--color-text-tertiary);font-family:monospace">#{{ $order['id'] }}</span>
                <div>
                    <p style="font-size:13px;font-weight:500;color:var(--color-text-primary);margin:0">{{ $order['customer'] }}</p>
                    <p style="font-size:11px;color:var(--color-text-tertiary);margin:0">{{ $order['date'] }}</p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span class="rpt-badge" style="background:{{ $badge[0] }};color:{{ $badge[1] }}">{{ str_replace('_',' ',$order['status']) }}</span>
                <span style="font-size:13px;font-weight:600;color:var(--color-text-primary)">₱{{ number_format($order['total'],0) }}</span>
            </div>
        </div>
        @endforeach
    </div>

    <div class="rpt-card">
        <h3>Inventory Summary</h3>
        <div class="rpt-stat-grid">
            <div class="rpt-stat-box" style="background:var(--color-background-tertiary)">
                <p style="color:var(--color-text-primary)">{{ $inventoryStats['total_products'] }}</p>
                <p>Total Products</p>
            </div>
            <div class="rpt-stat-box" style="background:var(--color-background-tertiary)">
                <p style="color:var(--color-text-primary)">{{ number_format($inventoryStats['total_stock']) }}</p>
                <p>Units in Stock</p>
            </div>
            <div class="rpt-stat-box" style="background:#fefce8">
                <p style="color:#a16207">{{ $inventoryStats['low_stock'] }}</p>
                <p style="color:#ca8a04">Low Stock</p>
            </div>
            <div class="rpt-stat-box" style="background:#fef2f2">
                <p style="color:#dc2626">{{ $inventoryStats['out_of_stock'] }}</p>
                <p style="color:#ef4444">Out of Stock</p>
            </div>
        </div>
        <hr class="rpt-divider">
        <p style="font-size:11px;font-weight:500;color:var(--color-text-tertiary);text-transform:uppercase;letter-spacing:.05em;margin:0 0 10px">Recent Stock Activity</p>
        @foreach($recentLogs as $log)
        <div class="rpt-log-row">
            <span style="color:var(--color-text-secondary);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $log['product'] }}</span>
            <span style="font-weight:600;{{ $log['qty']>0?'color:#22c55e':'color:#ef4444' }}">{{ $log['qty']>0?'+':'' }}{{ $log['qty'] }}</span>
            <span style="color:var(--color-text-tertiary)">{{ $log['date'] }}</span>
        </div>
        @endforeach
        <hr class="rpt-divider">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;color:var(--color-text-tertiary)">Est. Stock Value</span>
            <span style="font-size:14px;font-weight:700;color:var(--color-text-primary)">₱{{ number_format($inventoryStats['stock_value'],2) }}</span>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     CHARTS
════════════════════════════════════════════════════════════ --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function() {
    const isDark     = document.documentElement.classList.contains('dark');
    const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const labelColor = isDark ? '#9ca3af' : '#6b7280';

    // ── Sales / Revenue Line Chart ─────────────────────────
    const salesData   = @json($salesChart);
    const compareData = @json($compareChart);
    const hasCompare  = @json($compareEnabled) && compareData.length > 0;

    const salesCtx = document.getElementById('salesChart');
    if (salesCtx && salesData.length) {
        const datasets = [
            {
                label: 'Revenue (₱)',
                data: salesData.map(r => r.revenue),
                borderColor: '#f97316',
                backgroundColor: 'rgba(249,115,22,0.1)',
                borderWidth: 2,
                pointRadius: salesData.length > 20 ? 0 : 3,
                pointBackgroundColor: '#f97316',
                fill: true, tension: 0.4, yAxisID: 'y'
            },
            {
                label: 'Orders',
                data: salesData.map(r => r.orders),
                borderColor: '#60a5fa',
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                borderDash: [4, 4],
                pointRadius: 0,
                fill: false, tension: 0.4, yAxisID: 'y1'
            }
        ];

        if (hasCompare) {
            // Align compare data to same length as main (pad or trim)
            const cLen = salesData.length;
            const padded = Array.from({length: cLen}, (_, i) => compareData[i]?.revenue ?? null);
            datasets.push({
                label: 'Revenue – Compare (₱)',
                data: padded,
                borderColor: '#a78bfa',
                backgroundColor: 'rgba(167,139,250,0.06)',
                borderWidth: 2,
                borderDash: [6, 3],
                pointRadius: 0,
                fill: true, tension: 0.4, yAxisID: 'y'
            });
        }

        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesData.map(r => r.label),
                datasets
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false }, // we use our own legend
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                if (ctx.datasetIndex === 1) return ` ${ctx.raw} orders`;
                                return ` ₱${Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits:2})}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: {color: labelColor, font:{size:10}}, grid: {color: gridColor} },
                    y: {
                        position: 'left',
                        ticks: {color: labelColor, font:{size:10}, callback: v => '₱' + Number(v).toLocaleString()},
                        grid: {color: gridColor}
                    },
                    y1: {
                        position: 'right',
                        ticks: {color: '#60a5fa', font:{size:10}},
                        grid: {drawOnChartArea: false}
                    }
                }
            }
        });
    }

    // ── Status Donut ───────────────────────────────────────
    const statusData = @json($ordersByStatus);
    const statusCtx  = document.getElementById('statusChart');
    const statusColors = {
        pending:'#fbbf24', processing:'#60a5fa', shipped:'#a78bfa',
        ready_for_pickup:'#34d399', completed:'#4ade80', cancelled:'#f87171'
    };
    if (statusCtx && statusData.length) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.map(r => r.status.replace(/_/g,' ')),
                datasets: [{
                    data: statusData.map(r => r.count),
                    backgroundColor: statusData.map(r => statusColors[r.status] ?? '#d1d5db'),
                    borderWidth: 0, hoverOffset: 4
                }]
            },
            options: {
                responsive: true, cutout: '72%',
                plugins: {
                    legend: {display: false},
                    tooltip: {callbacks: {label: ctx => ` ${ctx.label}: ${ctx.raw} orders`}}
                }
            }
        });
    }
})();
</script>
</x-filament-panels::page>