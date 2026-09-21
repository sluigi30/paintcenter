<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One report section, as a CSV.
 *
 * Builds the SAME payload the screen and the printed document build, from the
 * same parameters, so a downloaded file always agrees with the report it came
 * from. Like the print route, it computes nothing of its own.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $section): StreamedResponse
    {
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

        abort_unless(ReportExport::isExportable($section), 404);

        $report = ReportBuilder::make($period, [$section], [
            'top_n' => max(1, (int) $request->query('top_n', 10)),
        ])->build();

        $built = $report['sections'][0] ?? null;

        abort_unless($built, 404);

        $table = ReportExport::forSection($section, $built['label'], $built['data'], $report['period']);

        abort_unless($table, 404);

        return response()->streamDownload(function () use ($table) {
            $handle = fopen('php://output', 'w');

            // Excel reads a CSV as the system codepage unless it sees a BOM,
            // which turns colour names like "Café Noir" into mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $table['headers']);

            foreach ($table['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $table['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
