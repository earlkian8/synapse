<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Support\Reports\MlSignals;
use App\Support\Reports\Report;
use App\Support\Reports\ReportInsights;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Drives the Reports analytics workspace: one page that lists every report the user
 * may run and renders the selected one inline — its decision-making charts, ML
 * signals, totals and table. The same report+filters resolution backs three surfaces
 * so they can never drift: the inline view, the CSV export, and the on-demand LLM
 * insights (which re-run server-side, never trusting the client's numbers).
 */
class ReportController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly MlSignals $signals,
        private readonly ReportInsights $insights,
    ) {}

    /** The workspace: the report catalogue plus the active report rendered inline. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $available = $this->registry->forUser($user);

        $list = $available
            ->map(fn (Report $report): array => [
                'key' => $report->key(),
                'name' => $report->name(),
                'description' => $report->description(),
                'group' => $report->group(),
            ])
            ->values();

        if ($available->isEmpty()) {
            return Inertia::render('reports/index', ['reports' => [], 'active' => null]);
        }

        // The chosen report (?report=) when accessible, else the first one.
        $requested = $request->query('report');
        $report = ($requested ? $available->first(fn (Report $r): bool => $r->key() === $requested) : null)
            ?? $available->first();

        return Inertia::render('reports/index', [
            'reports' => $list,
            'active' => $this->run($request, $report),
        ]);
    }

    /** Generate (on demand) the LLM decision-support narrative for a report run. */
    public function insights(Request $request, string $report): JsonResponse
    {
        $instance = $this->resolve($request, $report);
        $params = $this->normalize($request, $instance);

        $rows = $instance->rows($params);

        return response()->json([
            'insights' => $this->insights->generate(
                $instance,
                $rows,
                $instance->summary($rows, $params),
                $instance->charts($rows, $params),
                $this->signals->forGroup($instance->group()),
                $params,
            ),
        ]);
    }

    /** Stream the full (unpaginated) result set as a CSV download. */
    public function export(Request $request, string $report): StreamedResponse
    {
        $instance = $this->resolve($request, $report);
        $params = $this->normalize($request, $instance);
        $columns = $instance->columns();

        $filename = $instance->key().'-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($instance, $params, $columns): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_map(fn (array $column): string => $column['label'], $columns));

            foreach ($instance->rows($params) as $row) {
                fputcsv($handle, array_map(fn (array $column) => $row[$column['key']] ?? '', $columns));
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Run a report for the current request and assemble its full inline payload —
     * charts and signals over the whole set, a paginated slice for the table.
     *
     * @return array<string, mixed>
     */
    private function run(Request $request, Report $report): array
    {
        $params = $this->normalize($request, $report);

        $rows = $report->rows($params);
        $summary = $report->summary($rows, $params);
        $charts = $report->charts($rows, $params);
        $signals = $this->signals->forGroup($report->group());

        $perPage = $this->perPage($request);
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        $slice = $rows->forPage($page, $perPage)->values();
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? 0 : $from + $slice->count() - 1;

        return [
            'key' => $report->key(),
            'name' => $report->name(),
            'description' => $report->description(),
            'group' => $report->group(),
            'filters' => $report->filters(),
            'columns' => $report->columns(),
            'applied' => $params,
            'rows' => $slice,
            'summary' => $summary,
            'charts' => $charts,
            'signals' => $signals,
            'ai_enabled' => $this->insights->enabled(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'from' => $from,
                'to' => $to,
                'total' => $total,
            ],
        ];
    }

    /** Resolve a report by slug, enforcing existence and the report's own permission. */
    private function resolve(Request $request, string $key): Report
    {
        $report = $this->registry->find($key);

        abort_if($report === null, 404);
        abort_unless($request->user()->hasPermissionTo($report->permission()), 403);

        return $report;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 25);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
    }

    /**
     * Turn the raw query string into the report's declared, validated filter params.
     *
     * @return array<string, mixed>
     */
    private function normalize(Request $request, Report $report): array
    {
        $params = [];

        foreach ($report->filters() as $filter) {
            switch ($filter['type']) {
                case 'daterange':
                    $start = $this->validDate($request->query('start'), $filter['default']['start']);
                    $end = $this->validDate($request->query('end'), $filter['default']['end']);

                    // A backwards range is almost always a slip — order it rather than return nothing.
                    if ($start > $end) {
                        [$start, $end] = [$end, $start];
                    }

                    $params['start'] = $start;
                    $params['end'] = $end;
                    break;

                case 'month':
                    $params[$filter['key']] = $this->validMonth($request->query($filter['key']), $filter['default']);
                    break;

                case 'select':
                    $value = $request->query($filter['key']);
                    $params[$filter['key']] = ($value === null || $value === '')
                        ? ($filter['default'] ?? 'all')
                        : (string) $value;
                    break;

                case 'search':
                    $params[$filter['key']] = trim((string) $request->query($filter['key'], ''));
                    break;
            }
        }

        return $params;
    }

    private function validDate(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (Throwable) {
                // fall through to the default
            }
        }

        return $fallback;
    }

    private function validMonth(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) ? $value : $fallback;
    }
}
