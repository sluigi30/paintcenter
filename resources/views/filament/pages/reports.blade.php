<x-filament-panels::page>
<style>
/* ════════════════════════════════════════════════════════════
   SCREEN LAYOUT
════════════════════════════════════════════════════════════ */
.rpt-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.rpt-grid-3{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px}
.rpt-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.rpt-grid-side{display:flex;flex-direction:column;gap:16px}

.rpt-card{background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);border-radius:12px;padding:20px}
.rpt-card h3{font-size:11px;font-weight:700;color:var(--color-text-tertiary);margin:0 0 16px 0;text-transform:uppercase;letter-spacing:.08em}

.rpt-kpi-label{font-size:11px;font-weight:500;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.06em;margin:0 0 6px 0}
.rpt-kpi-value{font-size:28px;font-weight:700;color:var(--color-text-primary);margin:0 0 4px 0;line-height:1;letter-spacing:-0.02em}
.rpt-kpi-change{font-size:11px;margin:0 0 4px;font-weight:600}
.rpt-kpi-compare{font-size:11px;color:var(--color-text-tertiary);margin:0}
.rpt-up{color:#16a34a}.rpt-down{color:#dc2626}.rpt-neutral{color:#94a3b8}

/* ── FILTER CONSOLE ─────────────────────────────────────────── */
.rpt-filter{background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);
    border-radius:14px;margin-bottom:20px;overflow:hidden;
    box-shadow:0 1px 2px rgba(0,0,0,.04),0 4px 16px -8px rgba(0,0,0,.06)}
.rpt-filter-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;
    padding:16px 20px;border-bottom:1px solid var(--color-border-tertiary)}
.rpt-filter-eyebrow{font-size:10px;font-weight:800;color:#ea580c;text-transform:uppercase;letter-spacing:.14em;margin:0 0 4px}
.rpt-filter-period{font-size:16px;font-weight:700;color:var(--color-text-primary);margin:0;letter-spacing:-.015em;line-height:1.2}
.rpt-filter-sub{font-size:11.5px;color:var(--color-text-tertiary);margin:3px 0 0}
.rpt-filter-body{display:flex;flex-wrap:wrap;align-items:stretch;gap:20px;padding:14px 20px 16px}

.rpt-group{display:flex;flex-direction:column;gap:7px;justify-content:flex-start}
.rpt-group-label{font-size:10px;font-weight:700;color:var(--color-text-tertiary);
    text-transform:uppercase;letter-spacing:.09em;white-space:nowrap;line-height:1}
.rpt-vsep{width:1px;background:var(--color-border-tertiary);align-self:stretch;flex-shrink:0}

/* segmented preset control */
.rpt-seg{display:inline-flex;align-items:center;background:var(--color-background-tertiary);
    border:1px solid var(--color-border-tertiary);border-radius:10px;padding:3px;gap:2px}
.rpt-seg button{padding:6px 13px;border-radius:8px;font-size:12px;font-weight:600;border:none;
    background:transparent;color:var(--color-text-secondary);cursor:pointer;transition:all .15s;white-space:nowrap;line-height:1.2}
.rpt-seg button:hover{color:var(--color-text-primary)}
.rpt-seg button.active{background:#ea580c;color:#fff;box-shadow:0 1px 3px rgba(234,88,12,.4)}

/* unified date-range field */
.rpt-range{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--color-border-secondary);
    background:var(--color-background-tertiary);border-radius:10px;padding:6px 12px;
    transition:border-color .15s,box-shadow .15s}
.rpt-range:focus-within{border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.12)}
.rpt-range svg{color:var(--color-text-tertiary);flex-shrink:0}
.rpt-range input[type=date]{border:none;background:transparent;font-size:12px;font-weight:600;
    color:var(--color-text-primary);outline:none;padding:1px 0;font-family:inherit;cursor:pointer}
html.dark .rpt-range input[type=date]{color-scheme:dark}
.rpt-range-sep{font-size:11px;color:var(--color-text-tertiary);user-select:none}
.rpt-range-blue{border-color:rgba(96,165,250,.45)}
.rpt-range-blue:focus-within{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.rpt-days-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:700;
    color:var(--color-text-secondary);border:1px solid var(--color-border-tertiary);
    background:var(--color-background-tertiary);border-radius:999px;padding:4px 10px;white-space:nowrap}

/* comparison toggle pill */
.rpt-cmp-btn{display:inline-flex;align-items:center;gap:8px;padding:7px 15px;border-radius:10px;
    font-size:12px;font-weight:600;cursor:pointer;border:1px dashed var(--color-border-secondary);
    background:transparent;color:var(--color-text-secondary);transition:all .15s;line-height:1.2}
.rpt-cmp-btn:hover{border-color:#60a5fa;color:#60a5fa}
.rpt-cmp-btn.active{border:1px solid rgba(96,165,250,.5);background:rgba(96,165,250,.1);color:#3b82f6}
.rpt-cmp-dot{width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.35;flex-shrink:0}
.rpt-cmp-btn.active .rpt-cmp-dot{opacity:1}

/* revealed comparison strip */
.rpt-cmp-row{display:flex;flex-wrap:wrap;align-items:center;gap:14px;padding:12px 20px;
    border-top:1px dashed rgba(96,165,250,.35);background:rgba(96,165,250,.05)}
.rpt-cmp-row .rpt-group-label{color:#3b82f6}

/* export button */
.rpt-export{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(180deg,#f97316,#ea580c);
    color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:12.5px;font-weight:700;
    cursor:pointer;box-shadow:0 1px 3px rgba(234,88,12,.4);transition:all .15s;white-space:nowrap;line-height:1.2}
.rpt-export:hover{background:linear-gradient(180deg,#ea580c,#c2410c);box-shadow:0 3px 8px rgba(234,88,12,.45);transform:translateY(-1px)}
.rpt-export:active{transform:translateY(0)}

.rpt-cmp-table{width:100%;border-collapse:collapse;font-size:13px}
.rpt-cmp-table th{font-size:11px;font-weight:700;color:var(--color-text-tertiary);text-transform:uppercase;
    letter-spacing:.06em;padding:6px 12px;text-align:left;border-bottom:2px solid var(--color-border-tertiary)}
.rpt-cmp-table th:last-child,.rpt-cmp-table td:last-child{text-align:right}
.rpt-cmp-table td{padding:10px 12px;border-bottom:1px solid var(--color-border-tertiary);color:var(--color-text-primary)}
.rpt-cmp-table tr:last-child td{border-bottom:none}
.rpt-cmp-table td:first-child{color:var(--color-text-secondary);font-size:12px}
.rpt-cmp-period-a{font-weight:600}
.rpt-cmp-period-b{color:var(--color-text-secondary)}

.rpt-divider{border:none;border-top:1px solid var(--color-border-tertiary);margin:12px 0}
.rpt-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--color-border-tertiary)}
.rpt-row:last-child{border-bottom:none}
.rpt-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.rpt-bar-wrap{height:5px;background:var(--color-border-tertiary);border-radius:4px;overflow:hidden;margin-top:4px}
.rpt-bar-fill{height:100%;border-radius:4px}
.rpt-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.rpt-stat-box{border-radius:10px;padding:12px;text-align:center}
.rpt-stat-box p:first-child{font-size:22px;font-weight:700;margin:0 0 2px;letter-spacing:-0.02em}
.rpt-stat-box p:last-child{font-size:11px;margin:0;color:var(--color-text-tertiary)}
.rpt-log-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:5px 0;border-bottom:1px solid var(--color-border-tertiary)}
.rpt-log-row:last-child{border-bottom:none}
.rpt-product-name{font-size:13px;font-weight:500;color:var(--color-text-primary);margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px}
.rpt-product-meta{font-size:11px;color:var(--color-text-tertiary);margin:2px 0 4px}
.rpt-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.rpt-legend-item{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--color-text-secondary)}
.rpt-legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

@media(max-width:900px){
    .rpt-grid-4{grid-template-columns:1fr 1fr}.rpt-grid-3,.rpt-grid-2{grid-template-columns:1fr}
    .rpt-vsep{display:none}
    .rpt-filter-body{flex-direction:column;align-items:stretch;gap:14px}
    .rpt-seg{flex-wrap:wrap}
}
@media(max-width:500px){.rpt-grid-4{grid-template-columns:1fr}}

/* ════════════════════════════════════════════════════════════
   PRINT — Professional Report  +  Dark-mode kill switch
════════════════════════════════════════════════════════════ */
@media print {
    @page { margin: 1.8cm 2cm; size: A4 portrait; }

    /* ── DARK MODE KILL SWITCH ──────────────────────────── */
    html { color-scheme: light !important; }

    /* Override Tailwind 4 / Filament gray-scale variables */
    html, html.dark, * {
        --gray-50:  #f9fafb !important;
        --gray-100: #f3f4f6 !important;
        --gray-200: #e5e7eb !important;
        --gray-300: #d1d5db !important;
        --gray-400: #9ca3af !important;
        --gray-500: #6b7280 !important;
        --gray-600: #4b5563 !important;
        --gray-700: #374151 !important;
        --gray-800: #1f2937 !important;
        --gray-900: #111827 !important;
        --gray-950: #030712 !important;
        /* Filament / custom semantic vars used in blade */
        --color-background-secondary: #ffffff !important;
        --color-background-tertiary:  #f3f4f6 !important;
        --color-border-secondary:     #d1d5db !important;
        --color-border-tertiary:      #e5e7eb !important;
        --color-text-primary:         #111827 !important;
        --color-text-secondary:       #374151 !important;
        --color-text-tertiary:        #6b7280 !important;
    }

    /* Force white page background */
    html, html.dark,
    html body, html.dark body,
    .fi-body, .fi-main, .fi-page, main { background: white !important; color: #111827 !important; }

    /* Hide Filament navigation chrome */
    aside, nav, .fi-sidebar, .fi-topbar, .fi-header,
    [class*="fi-sidebar"], [class*="fi-topbar"] { display: none !important; }
    main, .fi-main, .fi-page { margin: 0 !important; padding: 0 !important; }

    /* Visibility helpers */
    .no-print   { display: none !important; }
    .print-only { display: block !important; }
    .print-flex { display: flex !important; }
    .print-break { page-break-before: always; break-before: page; }
    .print-avoid { page-break-inside: avoid; break-inside: avoid; }

    /* Kill canvases — replaced by data tables */
    canvas { display: none !important; }

    /* Colour fidelity for backgrounds/badges */
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    /* ── TYPOGRAPHY ─────────────────────────────────────── */
    body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; font-size: 9pt; color: #111827; }

    /* ── SECTION HEADER ─────────────────────────────────── */
    .prt-sec {
        display: flex; align-items: center; gap: 10pt;
        margin: 16pt 0 8pt; padding: 5pt 10pt;
        border-left: 3pt solid #ea580c; background: #fff7ed;
        page-break-inside: avoid;
    }
    .prt-sec-num  { font-size: 7pt; font-weight: 800; color: #ea580c; letter-spacing: .12em; white-space: nowrap; }
    .prt-sec-title{ font-size: 11pt; font-weight: 700; color: #1f2937; margin: 0; }

    /* ── KPI GRID ───────────────────────────────────────── */
    .prt-kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 8pt; margin-bottom: 10pt; }
    .prt-kpi-box  {
        border: 1px solid #e5e7eb; border-top: 3pt solid #ea580c;
        border-radius: 5pt; padding: 9pt 10pt; background: white;
        page-break-inside: avoid;
    }
    .prt-kpi-lbl  { font-size: 7pt; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .08em; margin: 0 0 3pt; }
    .prt-kpi-val  { font-size: 17pt; font-weight: 800; color: #111827; margin: 0 0 2pt; letter-spacing: -.02em; line-height: 1; }
    .prt-kpi-chg  { font-size: 7.5pt; font-weight: 700; margin: 0; }
    .prt-kpi-cmp  { font-size: 7pt; color: #6b7280; margin: 1pt 0 0; }

    /* ── PROFESSIONAL TABLE ─────────────────────────────── */
    .prt-tbl { width: 100%; border-collapse: collapse; font-size: 8pt; page-break-inside: avoid; }
    .prt-tbl th {
        background: #1f2937 !important; color: white !important;
        padding: 5pt 8pt; text-align: left; font-size: 7.5pt;
        font-weight: 600; letter-spacing: .03em;
    }
    .prt-tbl th.r, .prt-tbl td.r { text-align: right; }
    .prt-tbl th.c, .prt-tbl td.c { text-align: center; }
    .prt-tbl td { padding: 4.5pt 8pt; border-bottom: 1px solid #f0f0f0; color: #1f2937; }
    .prt-tbl tr:nth-child(even) td { background: #f9fafb !important; }
    .prt-tbl .prt-orange-hd { background: #ea580c !important; }
    .prt-up { color: #16a34a !important; font-weight: 700; }
    .prt-dn { color: #dc2626 !important; font-weight: 700; }

    /* ── TWO-COL LAYOUT ─────────────────────────────────── */
    .prt-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14pt; }

    /* ── INVENTORY STAT BOXES ───────────────────────────── */
    .prt-inv-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 6pt; margin-bottom: 8pt; }
    .prt-inv-box  { border: 1px solid #e5e7eb; border-radius: 4pt; padding: 8pt; text-align: center; background: white; }
    .prt-inv-val  { font-size: 16pt; font-weight: 800; margin: 0 0 2pt; }
    .prt-inv-lbl  { font-size: 7pt; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; margin: 0; }

    /* ── PRINT FOOTER ───────────────────────────────────── */
    .prt-footer { margin-top: 18pt; padding-top: 6pt; border-top: 1pt solid #e5e7eb; display: flex; justify-content: space-between; font-size: 7pt; color: #9ca3af; }
}
</style>

{{-- ══════════════════════════════════════════════════════════
     PRINT-ONLY: LETTERHEAD
════════════════════════════════════════════════════════════ --}}
<div class="print-only" style="display:none;margin-bottom:4pt">
    <div style="border-bottom:2.5pt solid #ea580c;padding-bottom:10pt;margin-bottom:14pt">
        <div class="print-flex" style="display:none;justify-content:space-between;align-items:flex-end">
            <div>
                <p style="font-size:7pt;font-weight:800;color:#ea580c;letter-spacing:.15em;text-transform:uppercase;margin:0 0 4pt">NCM Paint Center</p>
                <h1 style="font-size:22pt;font-weight:800;color:#111827;margin:0 0 5pt;letter-spacing:-.03em;line-height:1">Sales &amp; Operations Report</h1>
                <p style="font-size:9pt;color:#374151;margin:0">
                    {{ \Carbon\Carbon::parse($dateFrom)->format('F d, Y') }}
                    &mdash;
                    {{ \Carbon\Carbon::parse($dateTo)->format('F d, Y') }}
                    <span style="color:#9ca3af;margin-left:5pt">({{ $this->dayCount() }} days)</span>
                </p>
                @if($compareEnabled)
                <p style="font-size:8pt;color:#6b7280;margin:3pt 0 0">
                    Compared with: {{ \Carbon\Carbon::parse($compareFrom)->format('F d, Y') }} &ndash; {{ \Carbon\Carbon::parse($compareTo)->format('F d, Y') }}
                </p>
                @endif
            </div>
            <div style="text-align:right">
                <p style="font-size:7pt;color:#9ca3af;margin:0 0 2pt;text-transform:uppercase;letter-spacing:.07em">Report Generated</p>
                <p style="font-size:10pt;font-weight:700;color:#111827;margin:0">{{ now()->format('F d, Y') }}</p>
                <p style="font-size:8.5pt;color:#6b7280;margin:2pt 0 0">{{ now()->format('g:i A') }}</p>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     SCREEN: DATE TOOLBAR  (no-print)
════════════════════════════════════════════════════════════ --}}
<div class="rpt-filter no-print">
    <div class="rpt-filter-head">
        <div>
            <p class="rpt-filter-eyebrow">Reporting Period</p>
            <p class="rpt-filter-period">{{ $this->periodLabel() }}</p>
            <p class="rpt-filter-sub">
                {{ $this->dayCount() }} {{ \Illuminate\Support\Str::plural('day', $this->dayCount()) }} selected
                @if($compareEnabled)
                    &nbsp;·&nbsp; compared with {{ $this->comparePeriodLabel() }}
                @endif
            </p>
        </div>
        <button onclick="window.print()" class="rpt-export">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export Report
        </button>
    </div>

    <div class="rpt-filter-body">
        <div class="rpt-group">
            <span class="rpt-group-label">Quick Range</span>
            <div class="rpt-seg">
                @foreach(['7'=>'7D','30'=>'30D','90'=>'90D','mtd'=>'MTD','lm'=>'Last Month','365'=>'YTD'] as $val=>$label)
                    <button type="button" class="{{ $preset===$val?'active':'' }}" wire:click="applyPreset('{{ $val }}')">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="rpt-vsep"></div>

        <div class="rpt-group">
            <span class="rpt-group-label">Custom Range</span>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <div class="rpt-range">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <input type="date" wire:model.live="dateFrom" max="{{ $dateTo }}">
                    <span class="rpt-range-sep">–</span>
                    <input type="date" wire:model.live="dateTo" min="{{ $dateFrom }}" max="{{ now()->format('Y-m-d') }}">
                </div>
                <span class="rpt-days-badge">{{ $this->dayCount() }} {{ \Illuminate\Support\Str::plural('day', $this->dayCount()) }}</span>
            </div>
        </div>

        <div class="rpt-vsep"></div>

        <div class="rpt-group">
            <span class="rpt-group-label">Comparison</span>
            <button type="button" class="rpt-cmp-btn {{ $compareEnabled?'active':'' }}" wire:click="$toggle('compareEnabled')">
                <span class="rpt-cmp-dot"></span>
                {{ $compareEnabled ? 'Comparison on' : 'Compare periods' }}
            </button>
        </div>
    </div>

    @if($compareEnabled)
    <div class="rpt-cmp-row">
        <span class="rpt-group-label">Comparing Against</span>
        <div class="rpt-range rpt-range-blue">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <input type="date" wire:model.live="compareFrom" max="{{ $compareTo }}">
            <span class="rpt-range-sep">–</span>
            <input type="date" wire:model.live="compareTo" min="{{ $compareFrom }}">
        </div>
        <span style="font-size:11.5px;color:var(--color-text-tertiary)">
            Prior-period metrics are shown alongside every KPI, chart, and table below.
        </span>
    </div>
    @endif
</div>

{{-- ══════════════════════════════════════════════════════════
     PRINT-ONLY: SECTION 01 — Executive Summary
════════════════════════════════════════════════════════════ --}}
@php
    $rc = $this->revenueChange();
    $oc = $this->ordersChange();
    $ac = $this->avgOrderChange();
    $nc = $this->newCustChange();
@endphp
<div class="print-only" style="display:none">
    <div class="prt-sec">
        <span class="prt-sec-num">01</span>
        <p class="prt-sec-title">Executive Summary</p>
    </div>
    <div class="prt-kpi-grid">
        @php
            $printKpis = [
                ['lbl'=>'Total Revenue',   'val'=>'₱'.number_format($totalRevenue,2),  'chg'=>$rc, 'cmp'=>'₱'.number_format($compareRevenue,2).' prior period'],
                ['lbl'=>'Total Orders',    'val'=>number_format($totalOrders),           'chg'=>$oc, 'cmp'=>number_format($compareOrders).' prior period'],
                ['lbl'=>'Avg Order Value', 'val'=>'₱'.number_format($avgOrderValue,2),  'chg'=>$ac, 'cmp'=>'₱'.number_format($compareAvgOrder,2).' prior period'],
                ['lbl'=>'New Customers',   'val'=>number_format($newCustomers),          'chg'=>$nc, 'cmp'=>number_format($compareNewCustomers).' prior | '.number_format($totalCustomers).' total'],
            ];
        @endphp
        @foreach($printKpis as $k)
        <div class="prt-kpi-box">
            <p class="prt-kpi-lbl">{{ $k['lbl'] }}</p>
            <p class="prt-kpi-val">{{ $k['val'] }}</p>
            <p class="prt-kpi-chg" style="color:{{ $k['chg']>0?'#16a34a':($k['chg']<0?'#dc2626':'#94a3b8') }}">
                {{ $k['chg']>0?'▲':($k['chg']<0?'▼':'—') }} {{ abs($k['chg']) }}%
            </p>
            @if($compareEnabled)
            <p class="prt-kpi-cmp">Prior: {{ $k['cmp'] }}</p>
            @endif
        </div>
        @endforeach
    </div>
    <p style="font-size:7.5pt;color:#6b7280;margin:0 0 6pt 2pt">
        Pending orders: <strong style="color:#111827">{{ $pendingOrders }}</strong>
        &nbsp;&middot;&nbsp; Total customers: <strong style="color:#111827">{{ number_format($totalCustomers) }}</strong>
        &nbsp;&middot;&nbsp; Est. stock value: <strong style="color:#111827">₱{{ number_format($inventoryStats['stock_value'],2) }}</strong>
    </p>
</div>

{{-- ══════════════════════════════════════════════════════════
     SCREEN: KPI CARDS  (no-print — replaced by prt-kpi-grid above)
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-4 no-print">
    @php
        $kpis = [
            ['label'=>'Total Revenue',    'value'=>'₱'.number_format($totalRevenue,2), 'change'=>$rc, 'compare'=>'₱'.number_format($compareRevenue,2).' prior'],
            ['label'=>'Total Orders',     'value'=>number_format($totalOrders),          'change'=>$oc,  'compare'=>number_format($compareOrders).' prior'],
            ['label'=>'Avg Order Value',  'value'=>'₱'.number_format($avgOrderValue,2),  'change'=>$ac,'compare'=>'₱'.number_format($compareAvgOrder,2).' prior'],
            ['label'=>'New Customers',    'value'=>number_format($newCustomers),          'change'=>$nc, 'compare'=>number_format($compareNewCustomers).' prior | '.number_format($totalCustomers).' total'],
        ];
    @endphp
    @foreach($kpis as $kpi)
    <div class="rpt-card">
        <p class="rpt-kpi-label">{{ $kpi['label'] }}</p>
        <p class="rpt-kpi-value">{{ $kpi['value'] }}</p>
        <p class="rpt-kpi-change {{ $kpi['change']>0?'rpt-up':($kpi['change']<0?'rpt-down':'rpt-neutral') }}">
            {{ $kpi['change']>0?'▲':($kpi['change']<0?'▼':'—') }} {{ abs($kpi['change']) }}%
            @if($compareEnabled)<span style="font-weight:400;opacity:.7"> vs prior</span>@endif
        </p>
        @if($compareEnabled)
        <p class="rpt-kpi-compare">Prior: {{ $kpi['compare'] }}</p>
        @endif
    </div>
    @endforeach
</div>

{{-- ══════════════════════════════════════════════════════════
     COMPARISON — screen version  (no-print)
           +  SECTION 02 — print version
════════════════════════════════════════════════════════════ --}}
@if($compareEnabled)
@php
    $chgHtml = fn($v) => $v > 0
        ? '<span style="color:#16a34a;font-weight:700">▲ '.abs($v).'%</span>'
        : ($v < 0 ? '<span style="color:#dc2626;font-weight:700">▼ '.abs($v).'%</span>'
                  : '<span style="color:#94a3b8">—</span>');
@endphp

{{-- Screen --}}
<div class="rpt-card no-print" style="margin-bottom:20px">
    <h3>Period Comparison</h3>
    <p style="font-size:11px;color:var(--color-text-tertiary);margin:-8px 0 14px">
        {{ $this->periodLabel() }} &nbsp;vs&nbsp; {{ $this->comparePeriodLabel() }}
    </p>
    <table class="rpt-cmp-table">
        <thead>
            <tr>
                <th style="width:30%">Metric</th>
                <th><span class="rpt-cmp-period-a" style="color:#ea580c">●</span> {{ \Carbon\Carbon::parse($dateFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }}</th>
                <th><span style="color:#60a5fa">●</span> {{ \Carbon\Carbon::parse($compareFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($compareTo)->format('M d, Y') }}</th>
                <th>Change</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Total Revenue</td><td class="rpt-cmp-period-a">₱{{ number_format($totalRevenue,2) }}</td><td class="rpt-cmp-period-b">₱{{ number_format($compareRevenue,2) }}</td><td>{!! $chgHtml($rc) !!}</td></tr>
            <tr><td>Total Orders</td><td class="rpt-cmp-period-a">{{ number_format($totalOrders) }}</td><td class="rpt-cmp-period-b">{{ number_format($compareOrders) }}</td><td>{!! $chgHtml($oc) !!}</td></tr>
            <tr><td>Avg Order Value</td><td class="rpt-cmp-period-a">₱{{ number_format($avgOrderValue,2) }}</td><td class="rpt-cmp-period-b">₱{{ number_format($compareAvgOrder,2) }}</td><td>{!! $chgHtml($ac) !!}</td></tr>
            <tr><td>New Customers</td><td class="rpt-cmp-period-a">{{ number_format($newCustomers) }}</td><td class="rpt-cmp-period-b">{{ number_format($compareNewCustomers) }}</td><td>{!! $chgHtml($nc) !!}</td></tr>
        </tbody>
    </table>
</div>

{{-- Print: Section 02 —  Comparison --}}
<div class="print-only" style="display:none">
    <div class="prt-sec">
        <span class="prt-sec-num">02</span>
        <p class="prt-sec-title">Period Comparison &mdash; {{ $this->periodLabel() }} vs {{ $this->comparePeriodLabel() }}</p>
    </div>
    <table class="prt-tbl">
        <thead>
            <tr>
                <th style="width:28%">Metric</th>
                <th class="prt-orange-hd">{{ \Carbon\Carbon::parse($dateFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }}</th>
                <th>{{ \Carbon\Carbon::parse($compareFrom)->format('M d') }} – {{ \Carbon\Carbon::parse($compareTo)->format('M d, Y') }}</th>
                <th class="r">Change</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Total Revenue</td><td style="font-weight:700;color:#ea580c">₱{{ number_format($totalRevenue,2) }}</td><td>₱{{ number_format($compareRevenue,2) }}</td><td class="r {{ $rc>0?'prt-up':($rc<0?'prt-dn':'') }}">{!! $chgHtml($rc) !!}</td></tr>
            <tr><td>Total Orders</td><td style="font-weight:700;color:#ea580c">{{ number_format($totalOrders) }}</td><td>{{ number_format($compareOrders) }}</td><td class="r {{ $oc>0?'prt-up':($oc<0?'prt-dn':'') }}">{!! $chgHtml($oc) !!}</td></tr>
            <tr><td>Avg Order Value</td><td style="font-weight:700;color:#ea580c">₱{{ number_format($avgOrderValue,2) }}</td><td>₱{{ number_format($compareAvgOrder,2) }}</td><td class="r {{ $ac>0?'prt-up':($ac<0?'prt-dn':'') }}">{!! $chgHtml($ac) !!}</td></tr>
            <tr><td>New Customers</td><td style="font-weight:700;color:#ea580c">{{ number_format($newCustomers) }}</td><td>{{ number_format($compareNewCustomers) }}</td><td class="r {{ $nc>0?'prt-up':($nc<0?'prt-dn':'') }}">{!! $chgHtml($nc) !!}</td></tr>
        </tbody>
    </table>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     SCREEN: Charts row  (no-print)
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-3 no-print">
    <div class="rpt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
            <div>
                <h3 style="margin:0 0 4px">Revenue Over Time</h3>
                <span style="font-size:11px;color:var(--color-text-tertiary)">{{ $this->periodLabel() }}</span>
            </div>
        </div>
        <div class="rpt-legend">
            <div class="rpt-legend-item"><div class="rpt-legend-dot" style="background:#ea580c"></div> Revenue</div>
            <div class="rpt-legend-item"><div style="width:16px;height:2px;background:#60a5fa;flex-shrink:0"></div> Orders</div>
            @if($compareEnabled)
            <div class="rpt-legend-item"><div class="rpt-legend-dot" style="background:#a78bfa"></div> Revenue (compare)</div>
            @endif
        </div>
        <canvas id="rptSalesChart" style="max-height:230px"></canvas>
    </div>

    <div class="rpt-card">
        <h3>Orders by Status</h3>
        <div style="display:flex;justify-content:center;margin-bottom:16px">
            <canvas id="rptStatusChart" style="max-width:180px;max-height:180px"></canvas>
        </div>
        @foreach($ordersByStatus as $row)
        @php
            $sc = match($row['status']) {
                'pending'=>'#fbbf24','processing'=>'#60a5fa','shipped'=>'#a78bfa',
                'ready_for_pickup'=>'#34d399','completed'=>'#4ade80','cancelled'=>'#f87171',default=>'#d1d5db'};
        @endphp
        <div class="rpt-row">
            <div style="display:flex;align-items:center;gap:8px">
                <span style="width:8px;height:8px;border-radius:50%;background:{{ $sc }};flex-shrink:0;display:inline-block"></span>
                <span style="font-size:12px;color:var(--color-text-secondary);text-transform:capitalize">{{ str_replace('_',' ',$row['status']) }}</span>
            </div>
            <div style="text-align:right">
                <span style="font-size:12px;font-weight:700;color:var(--color-text-primary)">{{ $row['count'] }}</span>
                <span style="font-size:11px;color:var(--color-text-tertiary);margin-left:6px">₱{{ number_format($row['total'],0) }}</span>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     PRINT-ONLY: SECTION 03 — Sales Performance
════════════════════════════════════════════════════════════ --}}
<div class="print-only" style="display:none">
    <div class="prt-sec">
        <span class="prt-sec-num">03</span>
        <p class="prt-sec-title">Sales Performance &mdash; {{ $this->periodLabel() }}</p>
    </div>
    @if(count($salesChart))
    <table class="prt-tbl">
        <thead>
            <tr>
                <th>Period</th>
                <th class="r">Revenue (₱)</th>
                <th class="r">Orders</th>
                <th class="r">Avg / Order</th>
                @if($compareEnabled && count($compareChart))
                <th class="r">Compare Rev.</th>
                <th class="r">Change</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($salesChart as $i => $row)
            @php
                $avg = $row['orders'] > 0 ? $row['revenue'] / $row['orders'] : 0;
                $cRev = $compareChart[$i]['revenue'] ?? null;
                $rChg = ($cRev && $cRev > 0) ? round((($row['revenue'] - $cRev) / $cRev) * 100, 1) : null;
            @endphp
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="r">₱{{ number_format($row['revenue'],2) }}</td>
                <td class="r">{{ $row['orders'] }}</td>
                <td class="r">₱{{ number_format($avg,2) }}</td>
                @if($compareEnabled && count($compareChart))
                <td class="r">{{ isset($compareChart[$i]) ? '₱'.number_format($compareChart[$i]['revenue'],2) : '—' }}</td>
                <td class="r {{ $rChg===null?'':($rChg>0?'prt-up':'prt-dn') }}">
                    {{ $rChg===null ? '—' : (($rChg>0?'▲':'▼').' '.abs($rChg).'%') }}
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p style="font-size:8.5pt;color:#6b7280;font-style:italic">No sales data for this period.</p>
    @endif
</div>

{{-- ══════════════════════════════════════════════════════════
     SCREEN: Top Products + side panels  (no-print)
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-3 no-print">
    <div class="rpt-card">
        <h3>Top Selling Products</h3>
        @forelse($topProducts as $product)
        @php $maxRev = $topProducts[0]['total_revenue'] ?: 1; @endphp
        <div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--color-border-tertiary)">
            <div style="width:30px;height:30px;border-radius:50%;flex-shrink:0;background:#{{ ltrim($product['hex_code'],'#') }};border:2px solid var(--color-border-secondary)"></div>
            <div style="flex:1;min-width:0">
                <p class="rpt-product-name">{{ $product['name'] }}</p>
                <p class="rpt-product-meta">{{ $product['brand'] }} · {{ $product['total_qty'] }} units</p>
                <div class="rpt-bar-wrap"><div class="rpt-bar-fill" style="width:{{ min(100,($product['total_revenue']/$maxRev)*100) }}%;background:#ea580c"></div></div>
            </div>
            <span style="font-size:13px;font-weight:700;color:var(--color-text-primary);white-space:nowrap">₱{{ number_format($product['total_revenue'],0) }}</span>
        </div>
        @empty
        <p style="font-size:13px;color:var(--color-text-tertiary);text-align:center;padding:20px 0">No sales data for this period.</p>
        @endforelse
    </div>

    <div class="rpt-grid-side">
        <div class="rpt-card">
            <h3>Revenue by Category</h3>
            @forelse($topCategories as $cat)
            @php $catMax = $topCategories[0]['total_revenue'] ?: 1; @endphp
            <div class="rpt-row">
                <div style="flex:1;min-width:0">
                    <span style="font-size:12px;color:var(--color-text-secondary)">{{ $cat['category'] }}</span>
                    <div class="rpt-bar-wrap"><div class="rpt-bar-fill" style="width:{{ min(100,($cat['total_revenue']/$catMax)*100) }}%;background:#a78bfa"></div></div>
                </div>
                <div style="text-align:right;padding-left:12px;flex-shrink:0">
                    <span style="font-size:12px;font-weight:700;color:var(--color-text-primary)">₱{{ number_format($cat['total_revenue'],0) }}</span>
                    <span style="font-size:11px;color:var(--color-text-tertiary);display:block">{{ $cat['total_qty'] }} units</span>
                </div>
            </div>
            @empty
            <p style="font-size:12px;color:var(--color-text-tertiary)">No data yet.</p>
            @endforelse
        </div>

        <div class="rpt-card">
            <h3>Delivery vs Pickup</h3>
            @php $typeTotal = collect($revenueByType)->sum('count') ?: 1; @endphp
            @foreach($revenueByType as $type)
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
                    <span style="color:var(--color-text-secondary);text-transform:capitalize;font-weight:500">{{ $type['type'] }}</span>
                    <div style="text-align:right">
                        <span style="font-weight:700;color:var(--color-text-primary)">{{ $type['count'] }}</span>
                        <span style="color:var(--color-text-tertiary)"> orders · ₱{{ number_format($type['total'],0) }}</span>
                    </div>
                </div>
                <div class="rpt-bar-wrap">
                    <div class="rpt-bar-fill" style="width:{{ ($type['count']/$typeTotal)*100 }}%;background:{{ $type['type']==='delivery'?'#60a5fa':'#4ade80' }}"></div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     PRINT-ONLY: SECTIONS 04 + 05 — Products & Order Analysis
════════════════════════════════════════════════════════════ --}}
<div class="print-only" style="display:none">
    <div class="prt-cols">
        {{-- Top Products --}}
        <div>
            <div class="prt-sec">
                <span class="prt-sec-num">04</span>
                <p class="prt-sec-title">Top Products by Revenue</p>
            </div>
            <table class="prt-tbl">
                <thead><tr><th>Product</th><th>Brand</th><th class="r">Units</th><th class="r">Revenue</th></tr></thead>
                <tbody>
                    @forelse($topProducts as $p)
                    <tr>
                        <td>{{ $p['name'] }}</td>
                        <td style="color:#6b7280">{{ $p['brand'] }}</td>
                        <td class="r">{{ $p['total_qty'] }}</td>
                        <td class="r" style="font-weight:600">₱{{ number_format($p['total_revenue'],0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" style="color:#9ca3af;font-style:italic">No data</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="prt-sec" style="margin-top:12pt">
                <span class="prt-sec-num">06</span>
                <p class="prt-sec-title">Revenue by Category</p>
            </div>
            <table class="prt-tbl">
                <thead><tr><th>Category</th><th class="r">Units</th><th class="r">Revenue</th></tr></thead>
                <tbody>
                    @forelse($topCategories as $cat)
                    <tr>
                        <td>{{ $cat['category'] }}</td>
                        <td class="r">{{ $cat['total_qty'] }}</td>
                        <td class="r" style="font-weight:600">₱{{ number_format($cat['total_revenue'],0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" style="color:#9ca3af;font-style:italic">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Order Analysis --}}
        <div>
            <div class="prt-sec" style="margin-top:0">
                <span class="prt-sec-num">05</span>
                <p class="prt-sec-title">Orders by Status</p>
            </div>
            <table class="prt-tbl">
                <thead><tr><th>Status</th><th class="r">Count</th><th class="r">Revenue</th></tr></thead>
                <tbody>
                    @foreach($ordersByStatus as $row)
                    <tr>
                        <td style="text-transform:capitalize">{{ str_replace('_',' ',$row['status']) }}</td>
                        <td class="r">{{ $row['count'] }}</td>
                        <td class="r">₱{{ number_format($row['total'],0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="prt-sec" style="margin-top:12pt">
                <span class="prt-sec-num">07</span>
                <p class="prt-sec-title">Delivery vs Pickup</p>
            </div>
            <table class="prt-tbl">
                <thead><tr><th>Type</th><th class="r">Orders</th><th class="r">Revenue</th><th class="r">Share</th></tr></thead>
                <tbody>
                    @php $tt = collect($revenueByType)->sum('count') ?: 1; @endphp
                    @foreach($revenueByType as $type)
                    <tr>
                        <td style="text-transform:capitalize">{{ $type['type'] }}</td>
                        <td class="r">{{ $type['count'] }}</td>
                        <td class="r">₱{{ number_format($type['total'],0) }}</td>
                        <td class="r" style="color:#6b7280">{{ round(($type['count']/$tt)*100,1) }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     PAGE BREAK
════════════════════════════════════════════════════════════ --}}
<div class="print-only print-break" style="display:none"></div>

{{-- ══════════════════════════════════════════════════════════
     SCREEN: Recent Orders + Inventory  (no-print)
════════════════════════════════════════════════════════════ --}}
<div class="rpt-grid-2 no-print">
    <div class="rpt-card">
        <h3>Recent Orders</h3>
        @foreach($recentOrders as $order)
        @php
            [$bg,$fg] = match($order['status']) {
                'completed'=>['#dcfce7','#166534'],'cancelled'=>['#fee2e2','#991b1b'],
                'pending'=>['#fef9c3','#854d0e'],'shipped'=>['#ede9fe','#5b21b6'],
                'ready_for_pickup'=>['#d1fae5','#065f46'],default=>['#dbeafe','#1e40af']};
        @endphp
        <div class="rpt-row">
            <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
                <span style="font-size:10px;color:var(--color-text-tertiary);font-family:monospace;white-space:nowrap">#{{ $order['id'] }}</span>
                <div style="min-width:0">
                    <p style="font-size:13px;font-weight:500;color:var(--color-text-primary);margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $order['customer'] }}</p>
                    <p style="font-size:11px;color:var(--color-text-tertiary);margin:0">{{ $order['date'] }} · {{ ucfirst($order['type']) }}</p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;padding-left:8px">
                <span class="rpt-badge" style="background:{{ $bg }};color:{{ $fg }}">{{ str_replace('_',' ',$order['status']) }}</span>
                <span style="font-size:13px;font-weight:700;color:var(--color-text-primary)">₱{{ number_format($order['total'],0) }}</span>
            </div>
        </div>
        @endforeach
    </div>

    <div class="rpt-card">
        <h3>Inventory Summary</h3>
        <div class="rpt-stat-grid">
            <div class="rpt-stat-box" style="background:var(--color-background-tertiary)">
                <p style="color:var(--color-text-primary)">{{ $inventoryStats['total_products'] }}</p><p>Total Products</p>
            </div>
            <div class="rpt-stat-box" style="background:var(--color-background-tertiary)">
                <p style="color:var(--color-text-primary)">{{ number_format($inventoryStats['total_stock']) }}</p><p>Units in Stock</p>
            </div>
            <div class="rpt-stat-box" style="background:#fefce8">
                <p style="color:#a16207">{{ $inventoryStats['low_stock'] }}</p><p style="color:#ca8a04">Low Stock</p>
            </div>
            <div class="rpt-stat-box" style="background:#fef2f2">
                <p style="color:#dc2626">{{ $inventoryStats['out_of_stock'] }}</p><p style="color:#ef4444">Out of Stock</p>
            </div>
        </div>
        <hr class="rpt-divider">
        <p style="font-size:11px;font-weight:700;color:var(--color-text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Recent Stock Activity</p>
        @foreach($recentLogs as $log)
        <div class="rpt-log-row">
            <span style="color:var(--color-text-secondary);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px">{{ $log['product'] }}</span>
            <span style="font-weight:700;white-space:nowrap;{{ $log['qty']>0?'color:#16a34a':'color:#dc2626' }}">{{ $log['qty']>0?'+':'' }}{{ $log['qty'] }}</span>
            <span style="color:var(--color-text-tertiary);font-size:11px;white-space:nowrap;padding-left:12px">{{ $log['date'] }}</span>
        </div>
        @endforeach
        <hr class="rpt-divider">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;color:var(--color-text-tertiary)">Est. Stock Value</span>
            <span style="font-size:15px;font-weight:700;color:var(--color-text-primary)">₱{{ number_format($inventoryStats['stock_value'],2) }}</span>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     PRINT-ONLY: SECTIONS 08+09 — Inventory + Recent Orders
════════════════════════════════════════════════════════════ --}}
<div class="print-only" style="display:none">
    {{-- Inventory --}}
    <div class="prt-sec" style="margin-top:0">
        <span class="prt-sec-num">08</span>
        <p class="prt-sec-title">Inventory Snapshot</p>
    </div>
    <div class="prt-inv-grid">
        <div class="prt-inv-box">
            <p class="prt-inv-val" style="color:#111827">{{ $inventoryStats['total_products'] }}</p>
            <p class="prt-inv-lbl">Total Products</p>
        </div>
        <div class="prt-inv-box">
            <p class="prt-inv-val" style="color:#111827">{{ number_format($inventoryStats['total_stock']) }}</p>
            <p class="prt-inv-lbl">Units In Stock</p>
        </div>
        <div class="prt-inv-box" style="border-top:2pt solid #f59e0b">
            <p class="prt-inv-val" style="color:#d97706">{{ $inventoryStats['low_stock'] }}</p>
            <p class="prt-inv-lbl">Low Stock</p>
        </div>
        <div class="prt-inv-box" style="border-top:2pt solid #dc2626">
            <p class="prt-inv-val" style="color:#dc2626">{{ $inventoryStats['out_of_stock'] }}</p>
            <p class="prt-inv-lbl">Out of Stock</p>
        </div>
    </div>
    <div class="print-flex" style="display:none;justify-content:flex-end;margin-bottom:8pt">
        <p style="font-size:8.5pt;color:#374151;margin:0">
            Estimated Stock Value: <strong style="font-size:11pt;color:#ea580c">₱{{ number_format($inventoryStats['stock_value'],2) }}</strong>
        </p>
    </div>

    @if(count($recentLogs))
    <table class="prt-tbl" style="margin-bottom:14pt">
        <thead><tr><th>Product</th><th>Action</th><th class="r">Qty Change</th><th>Logged By</th><th>Date</th></tr></thead>
        <tbody>
            @foreach($recentLogs as $log)
            <tr>
                <td>{{ \Str::limit($log['product'],32) }}</td>
                <td style="text-transform:capitalize">{{ str_replace('_',' ',$log['action']) }}</td>
                <td class="r {{ $log['qty']>0?'prt-up':'prt-dn' }}" style="font-weight:700">{{ $log['qty']>0?'+':'' }}{{ $log['qty'] }}</td>
                <td style="color:#6b7280">{{ $log['admin'] }}</td>
                <td style="color:#6b7280;white-space:nowrap">{{ $log['date'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- Recent Orders --}}
    <div class="prt-sec">
        <span class="prt-sec-num">09</span>
        <p class="prt-sec-title">Recent Orders</p>
    </div>
    <table class="prt-tbl">
        <thead>
            <tr>
                <th class="c">#</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Type</th>
                <th>Status</th>
                <th>Payment</th>
                <th class="r">Total (₱)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentOrders as $order)
            <tr>
                <td class="c" style="font-family:monospace;color:#6b7280;font-size:7.5pt">{{ $order['id'] }}</td>
                <td style="font-weight:500">{{ $order['customer'] }}</td>
                <td style="color:#6b7280;white-space:nowrap">{{ $order['date'] }}</td>
                <td style="text-transform:capitalize">{{ $order['type'] }}</td>
                <td style="text-transform:capitalize">{{ str_replace('_',' ',$order['status']) }}</td>
                <td style="text-transform:capitalize;color:#6b7280">{{ $order['payment'] }}</td>
                <td class="r" style="font-weight:700">{{ number_format($order['total'],2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Footer --}}
    <div class="prt-footer">
        <span>NCM Paint Center &mdash; Confidential. For internal use only.</span>
        <span>Generated {{ now()->format('F d, Y \a\t g:i A') }}</span>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     CHART JS
════════════════════════════════════════════════════════════ --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = () => document.documentElement.classList.contains('dark');
    function gridColor()  { return isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)'; }
    function labelColor() { return isDark() ? '#9ca3af' : '#6b7280'; }
    const destroyChart = id => { const c = Chart.getChart(id); if (c) c.destroy(); };

    function buildSalesChart(salesData, compareData, hasCompare) {
        destroyChart('rptSalesChart');
        const ctx = document.getElementById('rptSalesChart');
        if (!ctx || !salesData.length) return;
        const datasets = [
            { label: 'Revenue (₱)', yAxisID: 'y', data: salesData.map(r => r.revenue),
              borderColor: '#ea580c', backgroundColor: 'rgba(234,88,12,0.08)',
              borderWidth: 2, fill: true, tension: 0.4,
              pointRadius: salesData.length > 20 ? 0 : 3, pointBackgroundColor: '#ea580c' },
            { label: 'Orders', yAxisID: 'y1', data: salesData.map(r => r.orders),
              borderColor: '#60a5fa', backgroundColor: 'transparent',
              borderWidth: 1.5, borderDash: [4,4], fill: false, tension: 0.4, pointRadius: 0 },
        ];
        if (hasCompare && compareData.length) {
            datasets.push({
                label: 'Revenue – Compare (₱)', yAxisID: 'y',
                data: Array.from({ length: salesData.length }, (_, i) => compareData[i]?.revenue ?? null),
                borderColor: '#a78bfa', backgroundColor: 'rgba(167,139,250,0.05)',
                borderWidth: 2, borderDash: [6,3], fill: true, tension: 0.4, pointRadius: 0,
            });
        }
        new Chart(ctx, {
            type: 'line', data: { labels: salesData.map(r => r.label), datasets },
            options: {
                responsive: true, interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => c.datasetIndex === 1
                        ? ` ${c.raw} orders`
                        : ` ₱${Number(c.raw).toLocaleString('en-PH',{minimumFractionDigits:2})}` } },
                },
                scales: {
                    x:  { ticks: { color: labelColor(), font: { size: 10 } }, grid: { color: gridColor() } },
                    y:  { position: 'left',  ticks: { color: labelColor(), font: { size: 10 }, callback: v => '₱'+Number(v).toLocaleString() }, grid: { color: gridColor() } },
                    y1: { position: 'right', ticks: { color: '#60a5fa', font: { size: 10 } }, grid: { drawOnChartArea: false } },
                },
            },
        });
    }

    function buildStatusChart(statusData) {
        destroyChart('rptStatusChart');
        const ctx = document.getElementById('rptStatusChart');
        if (!ctx || !statusData.length) return;
        const colors = { pending:'#fbbf24', processing:'#60a5fa', shipped:'#a78bfa',
            ready_for_pickup:'#34d399', completed:'#4ade80', cancelled:'#f87171' };
        new Chart(ctx, {
            type: 'doughnut',
            data: { labels: statusData.map(r => r.status.replace(/_/g,' ')),
                datasets: [{ data: statusData.map(r => r.count),
                    backgroundColor: statusData.map(r => colors[r.status] ?? '#d1d5db'),
                    borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, cutout: '72%',
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.raw} orders` } } } },
        });
    }

    function initCharts(sales, compare, status, hasCompare) {
        buildSalesChart(sales, compare, hasCompare);
        buildStatusChart(status);
    }

    const run = () => initCharts(
        @json($salesChart), @json($compareChart), @json($ordersByStatus), @json($compareEnabled)
    );

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }

    window.addEventListener('rpt:data', e => {
        const d = e.detail ?? {};
        initCharts(d.sales ?? [], d.compare ?? [], d.status ?? [], d.compareEnabled ?? false);
    });
})();
</script>
</x-filament-panels::page>
