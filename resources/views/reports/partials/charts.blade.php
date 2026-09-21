@props(['print' => false])

{{--
    One chart renderer, used by the admin page and by the printed document.

    Each canvas carries its own configuration in a data-chart attribute, put
    there by the shared x-rpt.chart component — so neither shell holds a copy of
    the chart setup and the two cannot drift.

    Colours are read from the CSS custom properties on .rpt-root rather than
    hard-coded, which is what lets the same chart render against the light
    surface, the dark surface and white paper without three configurations.
--}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    // Defined once and kept on window: Filament's SPA navigation re-runs inline
    // scripts on every visit, and a second copy of this would fight the first
    // over the same canvases.
    if (window.__rptCharts) return;

    const PRINT = @json($print);

    const state = { instances: {} };
    window.__rptCharts = state;

    function token(name, fallback) {
        const root = document.querySelector('.rpt-root') || document.documentElement;
        const value = getComputedStyle(root).getPropertyValue(name).trim();
        return value || fallback;
    }

    function peso(value) {
        return '₱' + Number(value).toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        });
    }

    function styleFor(role, isBar) {
        if (isBar) {
            // Bars carry their value in area, so they are filled rather than
            // stroked. 4px rounded ends on the data end only, anchored to the
            // baseline, and a gap between fills so adjacent bars stay distinct.
            var fill = role === 'ghost'
                ? token('--rpt-muted', '#898781')
                : token('--rpt-series-1', '#2a78d6');

            return {
                backgroundColor: fill,
                borderColor: fill,
                borderWidth: 0,
                borderRadius: 4,
                borderSkipped: 'start',
                barPercentage: 0.7,
                categoryPercentage: 0.7,
            };
        }

        if (role === 'ghost') {
            // The comparison period recedes: the current period is the subject,
            // and two equally loud lines make the reader hunt for which is which.
            return {
                borderColor: token('--rpt-muted', '#898781'),
                backgroundColor: 'transparent',
                borderDash: [5, 4],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 8,
            };
        }

        return {
            borderColor: token('--rpt-series-1', '#2a78d6'),
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 8,
            pointBackgroundColor: token('--rpt-series-1', '#2a78d6'),
            // A 2px surface ring keeps an overlapping marker legible.
            pointBorderColor: token('--rpt-surface', '#ffffff'),
            pointBorderWidth: 2,
        };
    }

    // Specs pushed by the server for this render. A canvas behind wire:ignore
    // keeps its ORIGINAL data-chart attribute forever, so once an update has
    // arrived the attribute is stale and must not be trusted again.
    state.pushed = {};

    function build(canvas) {
        const spec = state.pushed[canvas.id]
            || JSON.parse(canvas.dataset.chart || 'null');
        if (!spec) return null;

        const money = spec.value === 'money';
        const isBar = (spec.type || 'line') === 'bar';
        const ink = token('--rpt-muted', '#898781');
        const grid = token('--rpt-grid', '#e1e0d9');

        state.instances[canvas.id]?.destroy();

        const chart = new Chart(canvas, {
            type: spec.type || 'line',
            data: {
                labels: spec.labels || [],
                datasets: (spec.series || []).map(series => Object.assign({
                    label: series.label,
                    data: series.data,
                    tension: 0.3,
                    fill: false,
                }, styleFor(series.role, isBar))),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: PRINT ? false : { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // Two series always get a legend, so identity is never
                    // carried by colour alone. One series needs none - the
                    // caption names it.
                    legend: {
                        display: (spec.series || []).length > 1,
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, color: ink, font: { size: 11 }, usePointStyle: true },
                    },
                    tooltip: {
                        enabled: !PRINT,
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': ' + (money ? peso(ctx.parsed.y) : ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    // ONE y axis, always. Two measures of different scale get
                    // two charts side by side instead; a second axis invents a
                    // crossing point that is not in the data.
                    y: {
                        beginAtZero: true,
                        // Set when two periods are drawn side by side: without
                        // it each chart auto-scales and a halved period looks
                        // identical to a doubled one.
                        max: spec.y_max ?? undefined,
                        border: { display: false },
                        grid: { color: grid, drawTicks: false },
                        ticks: {
                            color: ink,
                            font: { size: 10 },
                            precision: money ? undefined : 0,
                            callback: v => (money ? peso(v) : v),
                            maxTicksLimit: 6,
                        },
                    },
                    x: {
                        border: { color: token('--rpt-axis', '#c3c2b7') },
                        grid: { display: false },
                        ticks: { color: ink, font: { size: 10 }, maxTicksLimit: 12, autoSkip: true },
                    },
                },
            },
        });

        state.instances[canvas.id] = chart;
        return chart;
    }

    state.render = function () {
        const canvases = Array.from(document.querySelectorAll('canvas.rpt-chart'));
        canvases.forEach(build);
        return canvases.length;
    };

    // Chart.js comes off a CDN and the canvases arrive with a Livewire morph,
    // so the first render waits for both rather than assuming either.
    function whenReady(attempt = 0) {
        if (window.Chart && document.querySelector('canvas.rpt-chart')) {
            requestAnimationFrame(() => {
                state.render();
                if (PRINT) {
                    // Print only once every chart has actually drawn, or the
                    // paper carries empty boxes where the charts should be.
                    requestAnimationFrame(() => window.print());
                }
            });
            return;
        }

        if (attempt < 80) setTimeout(() => whenReady(attempt + 1), 50);
        else if (PRINT) window.print(); // charts failed; the tables still print
    }

    document.addEventListener('DOMContentLoaded', () => whenReady());
    if (document.readyState !== 'loading') whenReady();

    if (!PRINT) {
        // Redraw after any Livewire update: the date range, the grouping or the
        // chosen sections may all have changed underneath.
        document.addEventListener('livewire:navigated', () => whenReady());

        // Livewire delivers the new specs with the update. Waiting a frame lets
        // the morph finish adding or removing canvases first - the split and
        // overlaid layouts do not have the same number of them.
        window.addEventListener('rpt:charts', event => {
            const specs = event.detail?.specs ?? event.detail?.[0]?.specs ?? {};
            Object.assign(state.pushed, specs);

            // A canvas that no longer exists must not keep a live Chart.js
            // instance attached to it.
            Object.keys(state.instances).forEach(id => {
                if (!document.getElementById(id)) {
                    state.instances[id].destroy();
                    delete state.instances[id];
                }
            });

            requestAnimationFrame(() => state.render());
        });
    }
})();
</script>
