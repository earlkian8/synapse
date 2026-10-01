<?php

namespace App\Support\Reports\Reports;

use App\Models\ActivityLog;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The audit trail: a chronological record of user-facing changes across the system —
 * who did what, to which record, and when. The core auditing report; date-bounded and
 * capped so it never reads the entire history at once.
 */
class AuditTrailReport implements Report
{
    use BuildsReport;

    /** Hard ceiling on rows pulled, regardless of window — keeps the report bounded. */
    private const MAX_ROWS = 5000;

    public function key(): string
    {
        return 'audit-trail';
    }

    public function name(): string
    {
        return 'Audit Trail';
    }

    public function description(): string
    {
        return 'A dated record of changes across the system — the who, what and when for auditing.';
    }

    public function group(): string
    {
        return 'System';
    }

    public function permission(): string
    {
        return 'activity-logs.view';
    }

    public function filters(): array
    {
        return [
            $this->dateRangeFilter('Period', now()->subDays(7), now()),
            $this->searchFilter('Action, record or person…'),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'logged_at', 'label' => 'Timestamp', 'align' => 'left', 'type' => 'text'],
            ['key' => 'causer', 'label' => 'Performed by', 'align' => 'left', 'type' => 'text'],
            ['key' => 'event', 'label' => 'Event', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'description', 'label' => 'Action', 'align' => 'left', 'type' => 'text'],
            ['key' => 'subject', 'label' => 'Record', 'align' => 'left', 'type' => 'text'],
        ];
    }

    public function rows(array $params): Collection
    {
        $query = ActivityLog::query()
            ->with('causer:id,first_name,middle_name,last_name,suffix')
            ->whereDate('created_at', '>=', $params['start'])
            ->whereDate('created_at', '<=', $params['end']);

        if ($params['search'] !== '') {
            $query->search($params['search']);
        }

        return $query
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'logged_at' => $log->created_at?->format('Y-m-d H:i') ?? '',
                'causer' => $log->causer?->full_name ?? 'System',
                'event' => ucfirst((string) $log->event),
                'description' => $log->description ?? '—',
                'subject' => $log->subject_label ?? '—',
            ])
            ->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        return [
            ['label' => 'Entries', 'value' => number_format($rows->count())],
            ['label' => 'People', 'value' => number_format($rows->pluck('causer')->unique()->count())],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->barsFromCounts('By event', $rows->groupBy('event')->map->count()->sortDesc()->take(8)->all()),
            $this->barsFromCounts('Most active', $rows->groupBy('causer')->map->count()->sortDesc()->take(6)->all()),
        ];
    }
}
