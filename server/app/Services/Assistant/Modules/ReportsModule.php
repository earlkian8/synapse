<?php

namespace App\Services\Assistant\Modules;

use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Reports\MlSignals;
use App\Support\Reports\Report;
use App\Support\Reports\ReportParameters;
use App\Support\Reports\ReportRegistry;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reports capability: run any report this user may open in the Reports
 * workspace, and answer from its figures.
 *
 * It adds no numbers of its own. A run is the workspace's run — the same
 * {@see Report} class, the same {@see ReportParameters} resolution, the same ML
 * signals — so a total quoted in chat is the total on the screen and in the CSV.
 * The model gets what the workspace's AI panel gets (totals, chart aggregates,
 * signals, a handful of rows), never the whole table, and writes the analysis
 * itself: no second model call.
 *
 * Every report keeps its own permission. The tool offers only the reports this
 * user may run (the report is an enum of their keys), and the run re-checks the
 * permission, so a report the user lost access to cannot be run by name. Filters
 * are held to what the report declares: a filter the report does not have, or a
 * value it does not know, is refused rather than quietly widened to "all".
 * Nothing here changes anything.
 */
class ReportsModule extends Module implements ContributesTopicContext
{
    /** How many rows of a run are shown to the model. */
    private const SAMPLE_ROWS = 10;

    /** How many columns of each sample row are spelled out. */
    private const SAMPLE_COLUMNS = 6;

    /** How many points of each chart are spelled out. */
    private const CHART_POINTS = 8;

    /** How many options of a select filter the catalogue lists. */
    private const LISTED_OPTIONS = 12;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportParameters $parameters,
        private readonly MlSignals $signals,
    ) {}

    public function key(): string
    {
        return 'reports';
    }

    /**
     * Available to anybody who may run at least one report.
     */
    public function isAvailable(User $user): bool
    {
        return $this->registry->forUser($user)->isNotEmpty();
    }

    protected function toolMap(): array
    {
        return [
            'list_reports' => 'listReports',
            'run_report' => 'runReport',
        ];
    }

    protected function readTools(): array
    {
        return ['run_report'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $reports = $this->catalog($this->registry->forUser($user)->map(fn (Report $r): string => "{$r->key()} ({$r->name()})"));

        return <<<TXT
        REPORTS — the Reports workspace: auditable reports with totals, charts and ML signals. Read-only.
        - run_report runs one report with filters and returns its totals, chart breakdowns, model signals and a few rows. Write the analysis yourself from what it returns — what is happening, what changed, why, and what to do — quoting its figures; never extrapolate beyond them.
        - list_reports describes each report's filters and their allowed values. Dates are YYYY-MM-DD, months YYYY-MM; a select filter takes one of its option names (a department's name, a status) or "all".
        - A run's result includes the link to open it in the workspace (/reports?…); offer it when useful.
          Reports this user may run: {$reports}
        TXT;
    }

    public function tools(User $user): array
    {
        $reports = $this->registry->forUser($user);

        if ($reports->isEmpty()) {
            return [];
        }

        return [
            [
                'name' => 'list_reports',
                'description' => 'The reports this user may run, each with its filters and their allowed values.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'run_report',
                'description' => 'Run one report and read its totals, chart breakdowns, ML signals and a sample of rows. Pass only filters that report has.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'report' => ['type' => 'STRING', 'enum' => $reports->map(fn (Report $r): string => $r->key())->values()->all()],
                        ...$this->filterProperties($reports),
                    ],
                    'required' => ['report'],
                ],
            ],
        ];
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'report', 'reports', 'turnover', 'attrition', 'net growth', 'hires and separations', 'movement',
            'masterlist', 'audit trail', 'analytics', 'ulat',
        ];
    }

    /**
     * Which reports this user can run — and, where they may see it, the last
     * twelve months of workforce movement, the figure these questions are most
     * often after.
     */
    public function topicContext(User $user): ?ContextSection
    {
        $reports = $this->registry->forUser($user);

        if ($reports->isEmpty()) {
            return null;
        }

        $lines = ['Reports this user can run: '.$reports->map(fn (Report $r): string => $r->name())->implode(', ').'.'];
        $movement = $reports->first(fn (Report $r): bool => $r->key() === 'workforce-movement');

        if ($movement !== null) {
            [$params] = $this->parameters->resolve($movement, [
                'start' => now()->subYear()->toDateString(),
                'end' => now()->toDateString(),
            ]);

            $rows = $movement->rows($params);
            $separations = $rows->where('event', 'Separated');

            $lines[] = 'Workforce movement, last 12 months ('.$params['start'].' to '.$params['end'].'): '
                .collect($movement->summary($rows, $params))->map(fn (array $s): string => "{$s['label']} {$s['value']}")->implode(', ').'.';

            if ($separations->isNotEmpty()) {
                $lines[] = 'Separations by kind: '.$this->tally($separations->groupBy('detail')->map->count()).'; by department: '.$this->tally($separations->groupBy('department')->map->count()->sortDesc()->take(5)).'.';
            }
        }

        return ContextSection::of('Reports', $lines, 'Run a report with run_report for anything narrower than this.');
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function listReports(User $user, array $args): ToolResult
    {
        $cards = $this->registry->forUser($user)->map(fn (Report $r): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $r->group(),
            title: $r->name(),
            subtitle: $r->description(),
            meta: array_map(fn (array $f): string => $this->describeFilter($f), $r->filters()),
            id: $r->key(),
        ))->values()->all();

        return ToolResult::found('Listed reports', count($cards).' available', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function runReport(User $user, array $args): ToolResult
    {
        $report = $this->registry->find((string) ($args['report'] ?? ''));

        // Offered only what the user may run, and checked again: a role can
        // change between the offer and the run.
        if ($report === null || ! $user->hasPermissionTo($report->permission())) {
            return $this->denied('run that report');
        }

        $declared = $this->inputKeys($report);
        $given = array_filter(
            array_diff_key($args, ['report' => true]),
            fn (mixed $value): bool => filled($value),
        );

        $foreign = array_diff(array_keys($given), $declared);

        if ($foreign !== []) {
            return ToolResult::error(
                "Ran {$report->name()}",
                $report->name().' has no '.implode(' or ', $foreign).' filter.'.($declared !== [] ? ' It takes: '.implode(', ', $declared).'.' : ' It takes no filters.'),
            );
        }

        [$params, $problems] = $this->parameters->resolve($report, $given);

        if ($problems !== []) {
            return ToolResult::error("Ran {$report->name()}", implode(' ', $problems).' Use list_reports to see the allowed values.');
        }

        $rows = $report->rows($params);
        $summary = $report->summary($rows, $params);
        $charts = $report->charts($rows, $params);
        $signals = $this->signals->forGroup($report->group());

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: $report->group(),
            title: $report->name(),
            subtitle: $this->describeParams($report, $params).' · '.$rows->count().' '.Str::plural('row', $rows->count()),
            meta: [
                'Totals: '.collect($summary)->map(fn (array $s): string => "{$s['label']} {$s['value']}")->implode(', '),
                ...array_map(fn (array $chart): string => $this->describeChart($chart), $charts),
                ...array_map(fn (array $signal): string => "Model signal — {$signal['label']}: {$signal['value']} ({$signal['detail']})", $signals),
                ...$this->sampleRows($report, $rows),
                'Open it: '.$this->link($report, $params),
            ],
            id: $report->key(),
        );

        return ToolResult::found("Ran {$report->name()}", $this->describeParams($report, $params), [$card]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * One declaration per filter key across the reports on offer, each saying
     * which reports take it — the model is given only these names.
     *
     * @param  Collection<int, Report>  $reports
     * @return array<string, array<string, string>>
     */
    private function filterProperties(Collection $reports): array
    {
        $properties = [];

        foreach ($reports as $report) {
            foreach ($report->filters() as $filter) {
                $keys = $filter['type'] === 'daterange' ? ['start', 'end'] : [$filter['key']];

                foreach ($keys as $key) {
                    $properties[$key] ??= ['label' => $this->inputLabel($filter, $key), 'reports' => []];
                    $properties[$key]['reports'][] = $report->name();
                }
            }
        }

        return collect($properties)->map(fn (array $p): array => [
            'type' => 'STRING',
            'description' => $p['label'].' — for '.implode(', ', array_unique($p['reports'])).'.',
        ])->all();
    }

    /**
     * The argument names a report takes.
     *
     * @return list<string>
     */
    private function inputKeys(Report $report): array
    {
        $keys = [];

        foreach ($report->filters() as $filter) {
            array_push($keys, ...($filter['type'] === 'daterange' ? ['start', 'end'] : [$filter['key']]));
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function inputLabel(array $filter, string $key): string
    {
        return match ($filter['type']) {
            'daterange' => ($key === 'start' ? 'Start' : 'End').' of the period, YYYY-MM-DD',
            'month' => 'Month, YYYY-MM',
            'search' => 'Search text',
            default => $filter['label'].': an option name, or "all"',
        };
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function describeFilter(array $filter): string
    {
        return match ($filter['type']) {
            'daterange' => "{$filter['label']}: start and end (YYYY-MM-DD), default {$filter['default']['start']} to {$filter['default']['end']}",
            'month' => "{$filter['label']}: month (YYYY-MM), default {$filter['default']}",
            'search' => 'search: free text',
            default => $filter['key'].': '.collect($filter['options'] ?? [])
                ->take(self::LISTED_OPTIONS)
                ->map(fn (array $o): string => (string) $o['label'])
                ->implode(', ').(count($filter['options'] ?? []) > self::LISTED_OPTIONS ? ', …' : ''),
        };
    }

    /**
     * The filters a run used, in words — the option labels, not their ids.
     *
     * @param  array<string, mixed>  $params
     */
    private function describeParams(Report $report, array $params): string
    {
        $parts = [];

        foreach ($report->filters() as $filter) {
            $parts[] = match ($filter['type']) {
                'daterange' => "{$params['start']} to {$params['end']}",
                'search' => $params[$filter['key']] !== '' ? 'matching “'.$params[$filter['key']].'”' : null,
                'select' => $params[$filter['key']] === ($filter['default'] ?? 'all')
                    ? null
                    : $filter['label'].': '.(collect($filter['options'] ?? [])->firstWhere('value', $params[$filter['key']])['label'] ?? $params[$filter['key']]),
                default => $filter['label'].' '.$params[$filter['key']],
            };
        }

        $parts = array_values(array_filter($parts));

        return $parts === [] ? 'No filters' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $chart
     */
    private function describeChart(array $chart): string
    {
        $points = collect($chart['type'] === 'donut' ? ($chart['segments'] ?? []) : ($chart['bars'] ?? []));

        return $chart['title'].': '.($points->isEmpty()
            ? 'nothing to show'
            : $points->take(self::CHART_POINTS)->map(fn (array $p): string => "{$p['label']} {$p['value']}")->implode(', ')
                .($points->count() > self::CHART_POINTS ? ' and '.($points->count() - self::CHART_POINTS).' more' : ''));
    }

    /**
     * The first rows, each as "Column: value" pairs.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function sampleRows(Report $report, Collection $rows): array
    {
        $columns = array_slice($report->columns(), 0, self::SAMPLE_COLUMNS);

        return $rows->take(self::SAMPLE_ROWS)->map(fn (array $row): string => 'Row: '.collect($columns)
            ->map(fn (array $c): string => $c['label'].' '.((string) ($row[$c['key']] ?? '') ?: '—'))
            ->implode(' · '))
            ->values()
            ->all();
    }

    /**
     * The workspace URL that opens exactly this run.
     *
     * @param  array<string, mixed>  $params
     */
    private function link(Report $report, array $params): string
    {
        $query = array_filter(['report' => $report->key(), ...$params], fn (mixed $v): bool => $v !== '' && $v !== null);

        return '/reports?'.http_build_query($query);
    }

    /**
     * @param  Collection<string|int, int>  $counts
     */
    private function tally(Collection $counts): string
    {
        return $counts->map(fn (int $count, string|int $label): string => "{$label} {$count}")->implode(', ');
    }
}
