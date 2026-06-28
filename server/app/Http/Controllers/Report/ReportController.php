<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Support\Reports\Report;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Drives every report through one runner: the hub, a parameterised view, and a CSV
 * export. Each request is resolved against {@see ReportRegistry} and re-authorised
 * against the report's own permission. Filters are normalised from the query string
 * before they reach a report, so a report only ever sees clean, validated params — and
 * the export runs the *same* report with the *same* params as the screen, so the file
 * always matches what was viewed.
 */
class ReportController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function __construct(private readonly ReportRegistry $registry) {}

    /** The report hub: every report the user may run, grouped by section. */
    public function index(Request $request): Response
    {
        $reports = $this->registry->forUser($request->user())
            ->map(fn (Report $report): array => [
                'key' => $report->key(),
                'name' => $report->name(),
                'description' => $report->description(),
                'group' => $report->group(),
            ])
            ->values();

        return Inertia::render('reports/index', ['reports' => $reports]);
    }

    /** Run a report for the current filters and return a paginated page of rows. */
    public function show(Request $request, string $report): Response
    {
        $instance = $this->resolve($request, $report);
        $params = $this->normalize($request, $instance);

        $rows = $instance->rows($params);
        $summary = $instance->summary($rows, $params);

        $perPage = $this->perPage($request);
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        $slice = $rows->forPage($page, $perPage)->values();
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? 0 : $from + $slice->count() - 1;

        return Inertia::render('reports/show', [
            'report' => [
                'key' => $instance->key(),
                'name' => $instance->name(),
                'description' => $instance->description(),
                'group' => $instance->group(),
                'filters' => $instance->filters(),
                'columns' => $instance->columns(),
            ],
            'applied' => $params,
            'rows' => $slice,
            'summary' => $summary,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'from' => $from,
                'to' => $to,
                'total' => $total,
            ],
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
