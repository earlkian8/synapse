<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Reports\Reports\AttendanceSummaryReport;
use App\Support\Reports\Reports\AuditTrailReport;
use App\Support\Reports\Reports\EmployeeMasterlistReport;
use App\Support\Reports\Reports\HeadcountSummaryReport;
use App\Support\Reports\Reports\LeaveLedgerReport;
use App\Support\Reports\Reports\RecruitmentPipelineReport;
use App\Support\Reports\Reports\WorkforceMovementReport;
use Illuminate\Support\Collection;

/**
 * The catalogue of every report in the system. Resolves report instances through the
 * container (so their query-class dependencies are injected) and filters them to those
 * the signed-in user is permitted to see.
 */
class ReportRegistry
{
    /**
     * Every report, in hub display order.
     *
     * @var list<class-string<Report>>
     */
    private const REPORTS = [
        EmployeeMasterlistReport::class,
        HeadcountSummaryReport::class,
        WorkforceMovementReport::class,
        AttendanceSummaryReport::class,
        LeaveLedgerReport::class,
        RecruitmentPipelineReport::class,
        AuditTrailReport::class,
    ];

    /**
     * All reports, keyed by their slug.
     *
     * @return Collection<string, Report>
     */
    public function all(): Collection
    {
        return collect(self::REPORTS)
            ->map(fn (string $class): Report => app($class))
            ->keyBy(fn (Report $report): string => $report->key());
    }

    /**
     * Resolve a report by slug, or null when it does not exist.
     */
    public function find(string $key): ?Report
    {
        return $this->all()->get($key);
    }

    /**
     * The reports the given user may view, in hub order.
     *
     * @return Collection<int, Report>
     */
    public function forUser(User $user): Collection
    {
        return $this->all()
            ->filter(fn (Report $report): bool => $user->hasPermissionTo($report->permission()))
            ->values();
    }
}
