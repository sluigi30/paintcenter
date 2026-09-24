{{--
    Styles for the report BLOCKS, shared by the admin page and the printed
    document. Both shells include this and supply their own token values, so a
    table looks right on screen and on paper without a second copy of the markup.

    Plain CSS on purpose: the admin panel compiles no custom Tailwind theme, so
    app views cannot use utility classes.
--}}
<style>
    .rpt-root {
        --rpt-radius: 10px;
        color: var(--rpt-ink);
    }

    /* -- Section shell -- */
    .rpt-section {
        background: var(--rpt-surface);
        border: 1px solid var(--rpt-border);
        border-radius: var(--rpt-radius);
        padding: 18px 20px;
        margin-bottom: 16px;
    }

    .rpt-section-head { margin-bottom: 14px; }

    .rpt-section-titlebar {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
    }

    .rpt-section-csv {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.06em;
        color: var(--rpt-muted);
        text-decoration: none;
        border: 1px solid var(--rpt-border);
        border-radius: 5px;
        padding: 2px 7px;
        flex: none;
    }

    .rpt-section-csv:hover { color: var(--rpt-ink); border-color: var(--rpt-axis); }

    .rpt-section-title {
        font-size: 15px;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--rpt-ink);
        margin: 0;
    }

    .rpt-section-desc {
        font-size: 12.5px;
        color: var(--rpt-ink-2);
        margin: 3px 0 0;
        max-width: 68ch;
    }

    /* A point-in-time block must say so, or the paper implies the figure
       belongs to the period on the cover. */
    .rpt-asof {
        display: inline-block;
        font-size: 11px;
        font-weight: 600;
        color: var(--rpt-ink-2);
        background: var(--rpt-plane);
        border: 1px solid var(--rpt-border);
        border-radius: 999px;
        padding: 2px 9px;
        margin: 7px 0 0;
    }

    .rpt-sub {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--rpt-ink-2);
        margin: 0 0 9px;
    }

    .rpt-note {
        font-size: 11.5px;
        line-height: 1.55;
        color: var(--rpt-ink-2);
        border-left: 2px solid var(--rpt-border);
        padding-left: 10px;
        margin: 12px 0 0;
        max-width: 78ch;
    }

    .rpt-empty {
        font-size: 12.5px;
        color: var(--rpt-muted);
        font-style: italic;
        margin: 6px 0;
    }

    /* -- Stat tiles -- */
    .rpt-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }

    .rpt-stat {
        background: var(--rpt-plane);
        border: 1px solid var(--rpt-border);
        border-radius: 8px;
        padding: 11px 13px;
    }

    .rpt-stat-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--rpt-muted);
        margin: 0 0 5px;
    }

    .rpt-stat-value {
        font-size: 21px;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--rpt-ink);
        margin: 0;
        font-variant-numeric: tabular-nums;
    }

    .rpt-stat-foot {
        display: flex;
        align-items: baseline;
        gap: 7px;
        flex-wrap: wrap;
        margin-top: 5px;
        min-height: 15px;
    }

    .rpt-stat-hint {
        font-size: 11px;
        color: var(--rpt-muted);
    }

    /* -- Delta: arrow + words, never colour alone -- */
    .rpt-delta {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 11.5px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .rpt-delta-arrow { font-size: 8px; }
    .rpt-delta-up   { color: var(--rpt-good); }
    .rpt-delta-down { color: var(--rpt-bad); }
    .rpt-delta-flat { color: var(--rpt-muted); }

    /* -- Ranked bar list --
       CSS bars rather than a canvas: they print exactly as they appear, need no
       JavaScript, and carry a direct label on every row. */
    .rpt-bars {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .rpt-bar-row { margin-bottom: 9px; }

    .rpt-bar-head {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
        margin-bottom: 3px;
    }

    .rpt-bar-label {
        font-size: 12.5px;
        color: var(--rpt-ink);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
    }

    .rpt-bar-meta {
        font-size: 11px;
        color: var(--rpt-muted);
    }

    .rpt-bar-value {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--rpt-ink);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .rpt-bar-track {
        height: 7px;
        background: var(--rpt-plane);
        border-radius: 4px;
        overflow: hidden;
    }

    .rpt-bar-fill {
        height: 100%;
        background: var(--rpt-series-1);
        border-radius: 4px;
    }

    /* -- Swatch: the paint colour itself, not an encoding of a value -- */
    .rpt-swatch {
        width: 13px;
        height: 13px;
        border-radius: 3px;
        border: 1px solid var(--rpt-border);
        display: inline-block;
        flex: none;
    }

    .rpt-swatch-none {
        background: repeating-linear-gradient(45deg, var(--rpt-plane), var(--rpt-plane) 3px, var(--rpt-border) 3px, var(--rpt-border) 5px);
    }

    /* -- Tables -- */
    .rpt-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
    }

    .rpt-table th {
        text-align: left;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--rpt-muted);
        border-bottom: 1px solid var(--rpt-border);
        padding: 0 8px 6px;
    }

    .rpt-table td {
        padding: 7px 8px;
        border-bottom: 1px solid var(--rpt-border);
        color: var(--rpt-ink);
        font-variant-numeric: tabular-nums;
    }

    .rpt-table tr:last-child td { border-bottom: 0; }

    /*
     * Scoped to the table on purpose. `.rpt-table th` sets text-align:left and
     * outranks a bare `.rpt-align-right` (0,1,1 beats 0,1,0), so the numeric
     * COLUMNS right-aligned their cells while their headings stayed left and
     * sat nowhere near the figures they labelled.
     */
    .rpt-table th.rpt-align-right,
    .rpt-table td.rpt-align-right { text-align: right; }

    .rpt-table th.rpt-align-center,
    .rpt-table td.rpt-align-center { text-align: center; }

    /* The first column takes the slack so the numbers gather on the right
       instead of one figure stranded in the middle of an empty row. */
    .rpt-table th:first-child,
    .rpt-table td:first-child { width: 100%; }

    /* A wrapped figure stops the column reading as a column. */
    .rpt-table th.rpt-align-right,
    .rpt-table td.rpt-align-right { white-space: nowrap; }

    /* -- Figures -- */
    .rpt-figure { margin: 0; min-width: 0; }

    /*
     * The canvas is pinned to fill this box and clipped to it.
     *
     * Chart.js writes its own inline width/height onto the canvas, sized from
     * whatever it measures the parent to be. Nothing here constrained it, so
     * when that measurement came out taller than the wrapper the chart painted
     * straight through the caption and over the section below it. Absolute
     * positioning plus overflow:hidden makes the wrapper's height the only
     * thing that can decide how much room a chart takes.
     */
    .rpt-canvas-wrap {
        position: relative;
        overflow: hidden;
        min-width: 0;
    }

    .rpt-canvas-wrap > canvas {
        position: absolute;
        top: 0;
        left: 0;
        display: block;
        /* Beats Chart.js's inline sizing, which is not marked important. */
        width: 100% !important;
        height: 100% !important;
    }

    .rpt-figcaption {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--rpt-muted);
        margin-top: 6px;
        text-align: center;
    }

    .rpt-figure-pair,
    .rpt-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }

    /* Grid items default to min-content width, which lets a wide chart or a
       long table push the pair past the page. */
    .rpt-figure-pair > *,
    .rpt-split > * {
        min-width: 0;
    }

    /* A pair holds ONE chart when the comparison is overlaid or there is no
       comparison at all; left in the first column it leaves the right half
       of the section empty. */
    .rpt-figure-pair > :only-child { grid-column: 1 / -1; }

    .rpt-chart-group + .rpt-chart-group { margin-top: 18px; }

    .rpt-scale-note {
        font-size: 10.5px;
        color: var(--rpt-muted);
        text-align: center;
        margin: 6px 0 0;
    }

    @media screen and (max-width: 860px) {
        .rpt-figure-pair,
        .rpt-split { grid-template-columns: 1fr; }
    }

    /* -- Definitions -- */
    .rpt-definitions { margin: 0; }

    .rpt-definitions dt {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--rpt-ink);
        margin-top: 10px;
    }

    .rpt-definitions dd {
        font-size: 12px;
        line-height: 1.55;
        color: var(--rpt-ink-2);
        margin: 2px 0 0;
        padding: 0;
        max-width: 80ch;
    }

    /* -- Signatories -- */
    .rpt-signatories {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 26px;
        margin-top: 26px;
    }

    .rpt-sign-name {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--rpt-ink);
        margin: 0 0 3px;
        min-height: 16px;
    }

    .rpt-sign-rule {
        border-top: 1px solid var(--rpt-axis);
        margin: 0 0 5px;
    }

    .rpt-sign-role {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--rpt-ink-2);
        margin: 0;
    }

    .rpt-sign-meta {
        font-size: 10.5px;
        color: var(--rpt-muted);
        margin: 2px 0 0;
    }
</style>
