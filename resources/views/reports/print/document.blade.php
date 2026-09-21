<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} &mdash; {{ $report['period']['label'] }}</title>

    {{--
        A standalone document. It loads none of the admin panel's CSS, which is
        why there is no dark-mode kill switch anywhere in this file: there is no
        dark mode here to fight. Paper is white, ink is black, and that is the
        only theme this page has.
    --}}
    <style>
        @php
            // Margin-box content can only be a literal string, so the running
            // header is baked into the CSS rather than read from the DOM.
            //
            // It has to be printed UNESCAPED: a style block is raw text, so
            // Blade's usual escaping would put a literal "&amp;" on the page.
            // That makes this the one place on the report where a query
            // parameter reaches the document unescaped, and $title comes
            // straight off the URL — so it is reduced to a safe alphabet here
            // rather than merely quoted. Dropping < > " and backslash means a
            // crafted title can neither close the string nor escape </style>.
            $runningHeader = preg_replace(
                '/[^A-Za-z0-9 ,.:;\/()&\'\-]/u',
                '',
                $title . ' - ' . $report['period']['label'],
            );
            $runningHeader = mb_substr(trim($runningHeader), 0, 90);
        @endphp

        @page {
            size: A4 portrait;
            margin: 16mm 13mm 16mm;

            /* Chrome does support margin boxes with page counters, so the page
               furniture needs no JavaScript and no position:fixed trickery. */
            @top-right {
                content: "{!! $runningHeader !!}";
                font: 7.5pt system-ui, -apple-system, "Segoe UI", sans-serif;
                color: #86857f;
            }

            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font: 7.5pt system-ui, -apple-system, "Segoe UI", sans-serif;
                color: #86857f;
            }

            @bottom-left {
                content: "NCM Paint Center - Confidential";
                font: 7.5pt system-ui, -apple-system, "Segoe UI", sans-serif;
                color: #86857f;
            }
        }

        /* Page one carries the full letterhead; repeating the short header
           above it would say the same thing twice. */
        @page :first {
            @top-right { content: ""; }
        }

        * { box-sizing: border-box; }

        html {
            color-scheme: light;
            background: #ffffff;
        }

        body {
            margin: 0;
            background: #ffffff;
            color: #0b0b0b;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 12px;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .rpt-root {
            --rpt-surface:  #ffffff;
            --rpt-plane:    #f6f6f4;
            --rpt-ink:      #0b0b0b;
            --rpt-ink-2:    #45443f;
            --rpt-muted:    #6f6e69;
            --rpt-grid:     #e1e0d9;
            --rpt-axis:     #b6b5ad;
            --rpt-border:   rgba(11, 11, 11, 0.16);
            --rpt-series-1: #2a78d6;
            --rpt-series-2: #eb6834;
            --rpt-good:     #006300;
            --rpt-bad:      #c0271f;
            --rpt-brand:    #b91c1c;

            max-width: 190mm;
            margin: 0 auto;
            padding: 0 8mm;
        }

        /* -- Letterhead -- */
        .prt-head {
            border-bottom: 2.5pt solid var(--rpt-brand);
            padding-bottom: 9pt;
            margin-bottom: 14pt;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 18pt;
        }

        .prt-org {
            font-size: 7.5pt;
            font-weight: 800;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--rpt-brand);
            margin: 0 0 4pt;
        }

        .prt-title {
            font-size: 20pt;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.05;
            margin: 0 0 5pt;
        }

        .prt-range { font-size: 9.5pt; color: #333; margin: 0; }
        .prt-compare { font-size: 8.5pt; color: #5c5b56; margin: 3pt 0 0; }

        .prt-meta { text-align: right; flex: none; }

        .prt-meta-label {
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #86857f;
            margin: 0 0 2pt;
        }

        .prt-meta-value { font-size: 10pt; font-weight: 700; margin: 0; }
        .prt-meta-time { font-size: 8.5pt; color: #5c5b56; margin: 2pt 0 0; }

        .prt-note {
            border: 1px solid var(--rpt-border);
            border-left: 3px solid var(--rpt-brand);
            border-radius: 5px;
            padding: 8pt 11pt;
            font-size: 10pt;
            margin-bottom: 12pt;
        }

        .rpt-section { margin-bottom: 12pt; }

        /*
         * PAGINATION.
         *
         * Everything here replaces a single blanket `break-inside: avoid` on
         * every section, which caused both of the printed report's faults: a
         * section that would not fit in the space left was pushed whole to the
         * next page, leaving the previous one half empty, and a section taller
         * than a page had to break anyway — wherever the browser chose, which
         * could be directly after a heading.
         *
         * The rule now is: long things flow and fill the page, small things
         * stay whole, and nothing ever separates a label from what it labels.
         */
        @media print {
            /* Per-section policy, declared in ReportSections. */
            .rpt-break-auto   { break-inside: auto; }
            .rpt-break-avoid  { break-inside: avoid; page-break-inside: avoid; }
            .rpt-break-before { break-before: page; page-break-before: always; }

            /* A heading never ends a page. */
            .rpt-section-head,
            .rpt-section-title,
            .rpt-sub,
            .rpt-definitions dt {
                break-after: avoid;
                page-break-after: avoid;
            }

            .rpt-section-head { break-inside: avoid; }

            /* Small atoms: cheap to keep whole, and unreadable when split.
               A stat tile in particular must never leave its label on one page
               and its number on the next. */
            .rpt-stat,
            .rpt-bar-row,
            .rpt-figure,
            .rpt-chart-group,
            .rpt-note,
            .rpt-sign,
            .rpt-signatories,
            .rpt-definitions dd {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            /* Tables flow, but never mid-row, and the column headings repeat on
               every continuation page so a row is always readable. */
            .rpt-table thead { display: table-header-group; }
            .rpt-table tfoot { display: table-footer-group; }
            .rpt-table tr { break-inside: avoid; page-break-inside: avoid; }

            /* Keep the heading, the column headings and the first rows together,
               so a table never starts with one stranded line. */
            .rpt-table tbody tr:nth-child(-n+2) {
                break-before: avoid;
                page-break-before: avoid;
            }

            body { orphans: 3; widows: 3; }

            /* Sized for the screen otherwise; on paper a shorter chart fits
               more per page and forces fewer breaks. Beats the inline height. */
            .rpt-canvas-wrap { height: 150px !important; }
        }

        .prt-foot {
            border-top: 1px solid var(--rpt-border);
            margin-top: 16pt;
            padding-top: 8pt;
            font-size: 8pt;
            color: #6f6e69;
            display: flex;
            justify-content: space-between;
            gap: 16pt;
        }

        .prt-toolbar {
            position: sticky;
            top: 0;
            background: #f6f6f4;
            border-bottom: 1px solid var(--rpt-border);
            padding: 9px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 12.5px;
            margin-bottom: 16px;
        }

        .prt-toolbar button {
            font: inherit;
            font-weight: 600;
            background: #b91c1c;
            color: #fff;
            border: 0;
            border-radius: 6px;
            padding: 6px 13px;
            cursor: pointer;
        }

        /* On paper the browser's own print dialog replaces it. */
        @media print {
            .prt-toolbar { display: none !important; }
            .rpt-root { padding: 0; max-width: none; }
        }
    </style>

    @include('reports.partials.styles')
</head>
<body>
    {{-- Shown on screen only: this page opens in a tab, so it needs to say what
         it is and offer the print dialog again if the auto-open was dismissed. --}}
    <div class="prt-toolbar">
        <span>Print preview &mdash; this is exactly what will be printed.</span>
        <button type="button" onclick="window.print()">Print / save as PDF</button>
    </div>

    <div class="rpt-root">
        <header class="prt-head">
            <div>
                <p class="prt-org">NCM Paint Center</p>
                <h1 class="prt-title">{{ $title }}</h1>
                <p class="prt-range">
                    {{ $report['period']['label'] }}
                    <span style="color:#86857f">({{ $report['period']['day_count'] }} {{ \Illuminate\Support\Str::plural('day', $report['period']['day_count']) }}, by {{ $report['period']['granularity_resolved'] }})</span>
                </p>
                @if($report['period']['has_comparison'])
                    <p class="prt-compare">
                        Compared with {{ $report['period']['compare_label'] }}
                        ({{ strtolower($report['period']['compare_mode_label']) }})
                    </p>
                @endif
            </div>
            <div class="prt-meta">
                <p class="prt-meta-label">Report generated</p>
                <p class="prt-meta-value">{{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('F j, Y') }}</p>
                <p class="prt-meta-time">{{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('g:i A') }}</p>
            </div>
        </header>

        @if($note)
            <p class="prt-note">{{ $note }}</p>
        @endif

        @foreach($report['sections'] as $section)
            @include('reports.sections.' . $section['key'], [
                'section' => $section,
                'data'    => $section['data'],
                'report'  => $report,
            ])
        @endforeach

        <footer class="prt-foot">
            <span>End of report.</span>
            <span>{{ $report['period']['label'] }}</span>
        </footer>
    </div>

    @include('reports.partials.charts', ['print' => true])
</body>
</html>
