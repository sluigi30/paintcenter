<?php

namespace App\Support\Reports;

use App\Services\Reports\Metric;

/**
 * One block of a report, declared once.
 *
 * The page this replaces wrote every block twice — once as screen markup and
 * again as print-only markup with inline point sizes — which is why it reached
 * a thousand lines and why "choose what goes on the paper" was never a real
 * feature. Here a section is declared once and both renderers loop the same
 * list, the printed one simply filtered by what the admin ticked.
 *
 * There is ONE partial per section, shared by the screen and the printed
 * document rather than one each. The two shells differ (a Filament page with a
 * toolbar; a standalone A4 document with a letterhead) but the blocks inside
 * them are the same markup, styled by whichever stylesheet is in scope. That is
 * the structural half of "the screen and the paper cannot disagree": there is
 * no second copy of a table to fall out of step, which is exactly how the old
 * page drifted.
 *
 * The view name is DERIVED from the key rather than stored, so a section can
 * never point at a partial belonging to another.
 */
final class ReportSection
{
    /** Flows across pages, filling whatever space is left. */
    public const BREAK_AUTO = 'auto';

    /** Short enough to keep whole; never split across a page boundary. */
    public const BREAK_AVOID = 'avoid';

    /** Always starts a fresh page. */
    public const BREAK_BEFORE = 'before';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,

        /** @var array<int, class-string<Metric>> */
        public readonly array $metrics = [],

        public readonly ?string $description = null,
        public readonly bool $defaultEnabled = true,
        public readonly bool $hasChart = false,

        /** Blocks that only make sense on paper, like the signature panel. */
        public readonly bool $printOnly = false,

        /**
         * How this section behaves at a page break.
         *
         * Every section used to be "avoid", which is why the printed report had
         * half-empty pages: a table that would not fit in the space left was
         * pushed whole to the next page rather than flowing into it. Worse, the
         * rule is only a hint — a section taller than a page has to break
         * somewhere, and the browser then chose the spot, which is how a
         * heading ended up stranded at the foot of a page.
         *
         * So only genuinely short sections keep it.
         */
        public readonly string $pageBreak = self::BREAK_AUTO,
    ) {}

    /** The one partial both renderers include. */
    public function view(): string
    {
        return "reports.sections.{$this->key}";
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
            'has_chart' => $this->hasChart,
            'print_only' => $this->printOnly,
            'page_break' => $this->pageBreak,
        ];
    }
}
