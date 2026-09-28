<?php

namespace App\Support\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns raw filter input into a report's declared, validated parameters — the one
 * place that knows what a report may be run with.
 *
 * The Reports workspace (from the query string) and the assistant (from a tool
 * call) both come through here, so a report is never run with a value it did not
 * declare:
 *
 * - a **daterange** takes `start` / `end` as YYYY-MM-DD, ordered if backwards;
 * - a **month** takes YYYY-MM;
 * - a **select** takes one of its options — by value, or, for a caller that
 *   speaks in words, by label ("Finance" for department 12). Anything else is
 *   not passed through: before, any string reached the report's query;
 * - a **search** is trimmed and bounded.
 *
 * What was given but not usable is reported back as a problem alongside the
 * default that was used instead. The workspace ignores the problems — a stale
 * URL should still open — while the assistant refuses, because "all departments"
 * is a wrong answer to a question about Narnia's department, not a fallback.
 */
class ReportParameters
{
    /** The longest search a report is run with. */
    private const MAX_SEARCH = 120;

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: list<string>} The params, and what could not be used.
     */
    public function resolve(Report $report, array $input): array
    {
        $params = [];
        $problems = [];

        foreach ($report->filters() as $filter) {
            $key = $filter['key'];

            switch ($filter['type']) {
                case 'daterange':
                    $start = $this->date($input['start'] ?? null, $filter['default']['start'], 'start', $problems);
                    $end = $this->date($input['end'] ?? null, $filter['default']['end'], 'end', $problems);

                    // A backwards range is almost always a slip — order it rather than return nothing.
                    if ($start > $end) {
                        [$start, $end] = [$end, $start];
                    }

                    $params['start'] = $start;
                    $params['end'] = $end;
                    break;

                case 'month':
                    $value = $input[$key] ?? null;
                    $valid = is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;

                    if (! $valid && filled($value)) {
                        $problems[] = "{$filter['label']} must be YYYY-MM.";
                    }

                    $params[$key] = $valid ? $value : $filter['default'];
                    break;

                case 'select':
                    $params[$key] = $this->option($filter, $input[$key] ?? null, $problems);
                    break;

                case 'search':
                    $params[$key] = Str::limit(trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''), self::MAX_SEARCH, '');
                    break;
            }
        }

        return [$params, $problems];
    }

    /**
     * A select's value: one of its options by value, else by label, else its
     * default.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $problems
     */
    private function option(array $filter, mixed $value, array &$problems): string
    {
        $default = (string) ($filter['default'] ?? 'all');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return $default;
        }

        $value = trim((string) $value);

        foreach ($filter['options'] ?? [] as $option) {
            if ((string) $option['value'] === $value) {
                return (string) $option['value'];
            }
        }

        foreach ($filter['options'] ?? [] as $option) {
            if (Str::lower((string) $option['label']) === Str::lower($value)) {
                return (string) $option['value'];
            }
        }

        $problems[] = '“'.Str::limit($value, 60).'” is not a '.Str::lower((string) $filter['label']).' this report knows.';

        return $default;
    }

    /**
     * @param  list<string>  $problems
     */
    private function date(mixed $value, string $fallback, string $which, array &$problems): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $value);

                if ($date !== null && $date->toDateString() === $value) {
                    return $value;
                }
            } catch (Throwable) {
                // fall through to the default
            }
        }

        if (filled($value)) {
            $problems[] = "The {$which} date must be YYYY-MM-DD.";
        }

        return $fallback;
    }
}
