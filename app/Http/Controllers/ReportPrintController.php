<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCharts;
use App\Services\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The printable report.
 *
 * A document of its own rather than the admin page put through window.print().
 * Printing the live page meant fighting Filament's dark mode with a three-layer
 * CSS kill switch, inheriting the browser's header and footer, and hiding every
 * canvas — so the old "export" produced a report with no charts on it at all.
 *
 * This route renders the SAME payload the screen renders, from the same
 * ReportBuilder, onto a standalone A4 layout that loads none of the panel's CSS.
 * It computes nothing of its own; if it did, the screen and the paper could
 * disagree, which is the whole failure this rebuild exists to end.
 */
class ReportPrintController extends Controller
{
    public function __invoke(Request $request)
    {
        // Behind the panel's own rule rather than a second definition of who an
        // admin is: archived admins lose the panel and must lose this too.
        abort_unless(
            $request->user()?->canAccessPanel(Filament::getPanel('admin')),
            403,
        );

        $period = ReportPeriod::make(
            $request->query('from') ?: now()->subDays(29)->toDateString(),
            $request->query('to') ?: now()->toDateString(),
            $request->query('compare_mode') ?: ReportPeriod::COMPARE_NONE,
            $request->query('compare_from') ?: null,
            $request->query('compare_to') ?: null,
            $request->query('granularity') ?: null,
        );

        $sections = array_values(array_filter(
            explode(',', (string) $request->query('sections', '')),
        ));

        $report = ReportBuilder::make(
            $period,
            $sections ?: null,
            [
                'top_n' => max(1, (int) $request->query('top_n', 10)),
                'comparison_layout' => $request->query('layout')
                    ?: ReportCharts::LAYOUT_SPLIT,
            ],
        )->build();

        return view('reports.print.document', [
            'report' => $report,
            'title' => $request->query('title') ?: 'Sales & Operations Report',
            'note' => $request->query('note'),
        ]);
    }
}
