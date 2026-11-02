<?php

namespace App\Support\Ml;

use App\Models\AttendanceRecord;
use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\EmployeePromotion;
use App\Models\LeaveRequest;
use App\Models\OffboardingCase;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PromotionReadinessScore;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Support\Performance\PerformanceScorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything HR should weigh beside one person's prediction (ADR 0099), for the
 * breakdown dialog of Attrition Risk, Promotion Readiness and Performance Forecast:
 *
 *  - `history` — the person's score in each past run of the same surface, oldest
 *    first (a forecast also carries the rating the period actually gave);
 *  - `signals` — their latest result on the other two surfaces, so a risk is read
 *    beside readiness and expected performance, and the reverse;
 *  - the record behind and around the model's inputs: employment, an open
 *    offboarding case, attendance by month, leave, completed appraisals by
 *    section, promotions, training and awards.
 *
 * Every block is gated by the permission of the module it comes from: a viewer who
 * cannot open Leave gets `leave: null`, not the person's leave. Each surface's own
 * history needs only that surface's view permission, which the route checks. All
 * reads go through tenant-scoped models, so another workspace's person 404s at the
 * route. Read-only: nothing is logged.
 */
final class PredictionContext
{
    /** The view permission of each predictive surface. */
    public const SURFACES = [
        'attrition' => 'analytics.attrition.view',
        'promotion' => 'analytics.promotion.view',
        'performance' => 'analytics.performance.view',
    ];

    /** How many past runs the trend shows. */
    private const HISTORY_RUNS = 12;

    /** Months of attendance shown, the current one included. */
    private const ATTENDANCE_MONTHS = 6;

    public function __construct(private readonly PerformanceScorer $scorer) {}

    /**
     * The context for one person on one surface, as the dialog reads it.
     *
     * @return array<string, mixed>
     */
    public function for(Employee $employee, string $surface, User $viewer): array
    {
        $employee->loadMissing(['position:id,title', 'department:id,name', 'manager:id,first_name,middle_name,last_name,suffix']);
        $today = CarbonImmutable::today();

        return [
            'employment' => $this->employment($employee, $viewer, $today),
            'history' => $this->history($employee, $surface),
            'signals' => collect(self::SURFACES)
                ->except($surface)
                ->filter(fn (string $permission): bool => $viewer->can($permission))
                ->map(fn (string $permission, string $other): ?array => $this->signal($employee, $other))
                ->all(),
            'offboarding' => $viewer->can('offboarding.view') ? $this->offboarding($employee) : null,
            'attendance' => $viewer->can('attendance.view') ? $this->attendance($employee, $today) : null,
            'leave' => $viewer->can('leave.view') ? $this->leave($employee, $today) : null,
            'appraisals' => $viewer->can('performance.view') ? $this->appraisals($employee) : null,
            'promotions' => $viewer->can('employees.view') ? $this->promotions($employee) : null,
            'training' => $viewer->can('training.view') ? $this->training($employee, $today) : null,
            'awards' => $viewer->can('awards.view') ? $this->awards($employee, $today) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employment(Employee $employee, User $viewer, CarbonImmutable $today): array
    {
        return [
            'hired_on' => $employee->date_hired?->toDateString(),
            'tenure_years' => $employee->date_hired ? max(0.0, round($employee->date_hired->diffInYears($today), 2)) : null,
            'employment_type' => $employee->employment_type,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'manager' => $employee->manager?->full_name,
            'profile_url' => $viewer->can('employees.view') ? route('employees.show', $employee) : null,
        ];
    }

    /**
     * The person's result in each past run of this surface, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Employee $employee, string $surface): array
    {
        $rows = match ($surface) {
            'attrition' => AttritionRiskScore::query()
                ->where('employee_id', $employee->id)
                ->with('run:id,created_at,status')
                ->get()
                ->map(fn (AttritionRiskScore $s): array => [
                    'run' => $s->run, 'score' => (float) $s->score, 'tier' => $s->tier,
                ]),
            'promotion' => PromotionReadinessScore::query()
                ->where('employee_id', $employee->id)
                ->with('run:id,created_at,status')
                ->get()
                ->map(fn (PromotionReadinessScore $s): array => [
                    'run' => $s->run, 'score' => (float) $s->score, 'tier' => $s->tier,
                ]),
            'performance' => $this->forecastHistory($employee),
            default => collect(),
        };

        return $rows
            ->filter(fn (array $row): bool => $row['run'] !== null && $row['run']->status === 'completed')
            ->sortBy(fn (array $row): int => $row['run']->created_at?->getTimestamp() ?? 0)
            ->take(-self::HISTORY_RUNS)
            ->map(fn (array $row): array => [
                'run' => $row['run']->hashid,
                'at' => $row['run']->created_at?->toIso8601String(),
                ...collect($row)->except('run')->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Past forecasts, each with the rating its period actually gave once appraised.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function forecastHistory(Employee $employee): Collection
    {
        $forecasts = PerformanceForecast::query()
            ->where('employee_id', $employee->id)
            ->with(['run:id,created_at,status,target_period_id', 'run.targetPeriod:id,name'])
            ->get();

        $actuals = PerformanceEvaluation::query()
            ->completed()
            ->where('employee_id', $employee->id)
            ->whereIn('evaluation_period_id', $forecasts->pluck('run.target_period_id')->filter()->unique())
            ->pluck('overall_percent', 'evaluation_period_id');

        return $forecasts->map(fn (PerformanceForecast $f): array => [
            'run' => $f->run,
            'score' => (float) $f->predicted_rating,
            'tier' => $f->band,
            'low' => $f->predicted_low === null ? null : (float) $f->predicted_low,
            'high' => $f->predicted_high === null ? null : (float) $f->predicted_high,
            'period' => $f->run?->targetPeriod?->name,
            'actual' => isset($actuals[$f->run?->target_period_id]) ? (float) $actuals[$f->run->target_period_id] : null,
        ]);
    }

    /**
     * The person's latest result on another surface, or null when no run has them.
     *
     * @return array<string, mixed>|null
     */
    private function signal(Employee $employee, string $surface): ?array
    {
        $model = match ($surface) {
            'attrition' => AttritionRiskScore::class,
            'promotion' => PromotionReadinessScore::class,
            'performance' => PerformanceForecast::class,
        };

        $latest = $model::query()
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query->where('status', 'completed'))
            ->with('run:id,created_at')
            ->latest('id')
            ->first();

        if ($latest === null || $latest->run === null) {
            return null;
        }

        $at = ['run' => $latest->run->hashid, 'at' => $latest->run->created_at?->toIso8601String()];

        return $latest instanceof PerformanceForecast
            ? [...$at, 'score' => (float) $latest->predicted_rating, 'tier' => $latest->band,
                'low' => $latest->predicted_low === null ? null : (float) $latest->predicted_low,
                'high' => $latest->predicted_high === null ? null : (float) $latest->predicted_high]
            : [...$at, 'score' => (float) $latest->score, 'tier' => $latest->tier];
    }

    /**
     * An offboarding case still in progress (a filed resignation, a contract ending).
     *
     * @return array<string, mixed>|null
     */
    private function offboarding(Employee $employee): ?array
    {
        $case = OffboardingCase::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['initiated', 'clearance'])
            ->latest('id')
            ->first();

        return $case === null ? null : [
            'type' => $case->type,
            'status' => $case->status,
            'notice_date' => $case->notice_date?->toDateString(),
            'last_working_day' => $case->last_working_day?->toDateString(),
        ];
    }

    /**
     * Attendance by month, the current month included: the days worked, absences,
     * late arrivals and overtime — the same counts the attrition model reads over 90
     * days ({@see AttritionFeatureMapper}), spread out so a change shows.
     *
     * @return array<string, mixed>
     */
    private function attendance(Employee $employee, CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->subMonths(self::ATTENDANCE_MONTHS - 1);

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $today->toDateString()])
            ->get(['work_date', 'status', 'late_minutes', 'overtime_minutes'])
            ->groupBy(fn (AttendanceRecord $r): string => CarbonImmutable::parse($r->work_date)->format('Y-m'));

        $months = collect(range(0, self::ATTENDANCE_MONTHS - 1))
            ->map(function (int $i) use ($from, $records): array {
                $month = $from->addMonths($i);
                $rows = $records->get($month->format('Y-m'), collect());

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->format('M'),
                    'tracked' => $rows->count(),
                    'worked' => $rows->whereIn('status', AttendanceRecord::PRESENT_STATUSES)->count(),
                    'absences' => $rows->where('status', 'absent')->count(),
                    'lates' => $rows->filter(fn (AttendanceRecord $r): bool => (int) $r->late_minutes > 0)->count(),
                    'late_minutes' => (int) $rows->sum('late_minutes'),
                    'overtime_hours' => round((int) $rows->sum('overtime_minutes') / 60, 1),
                ];
            })
            ->values()
            ->all();

        return ['months' => $months];
    }

    /**
     * Approved leave over the last twelve months by type, what is still waiting for
     * a decision, and what is booked ahead.
     *
     * @return array<string, mixed>
     */
    private function leave(Employee $employee, CarbonImmutable $today): array
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('end_date', '>=', $today->subYear()->toDateString())
            ->with('type:id,name')
            ->get();

        $approved = $requests->where('status', 'approved');
        $past = $approved->filter(fn (LeaveRequest $r): bool => $r->start_date->lte($today));

        return [
            'days_12m' => round((float) $past->sum('days'), 1),
            'by_type' => $past
                ->groupBy(fn (LeaveRequest $r): string => $r->type?->name ?? 'Leave')
                ->map(fn (Collection $group, string $type): array => [
                    'type' => $type,
                    'days' => round((float) $group->sum('days'), 1),
                    'requests' => $group->count(),
                ])
                ->sortByDesc('days')
                ->values()
                ->all(),
            'pending' => $requests->where('status', 'pending')->count(),
            'upcoming' => $approved
                ->filter(fn (LeaveRequest $r): bool => $r->start_date->gt($today))
                ->sortBy('start_date')
                ->take(3)
                ->map(fn (LeaveRequest $r): array => [
                    'type' => $r->type?->name ?? 'Leave',
                    'start' => $r->start_date->toDateString(),
                    'end' => $r->end_date->toDateString(),
                    'days' => (float) $r->days,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Completed appraisals, oldest first (the order the models read them in), each
     * with its sections' attainment — which part of the work carried the result.
     *
     * @return list<array<string, mixed>>
     */
    private function appraisals(Employee $employee): array
    {
        return PerformanceEvaluation::query()
            ->completed()
            ->where('employee_id', $employee->id)
            ->with(['period:id,name,start_date,end_date', 'scores'])
            ->get()
            ->sortBy(fn (PerformanceEvaluation $e): array => [$e->period?->end_date?->getTimestamp() ?? PHP_INT_MAX, $e->id])
            ->take(-6)
            ->map(function (PerformanceEvaluation $e): array {
                $sections = $e->scores->isEmpty()
                    ? []
                    : $this->scorer->score($e->scores, $e->template_bands ?? [])->sections;

                return [
                    'period' => $e->period?->name,
                    'ends' => $e->period?->end_date?->toDateString(),
                    'percent' => round((float) $e->overall_percent, 1),
                    'result' => $e->result_label,
                    'sections' => collect($sections)
                        ->filter(fn (array $s): bool => $s['percent'] !== null)
                        ->map(fn (array $s): array => [
                            'name' => $s['name'] ?? 'Overall',
                            'percent' => round((float) $s['percent'], 1),
                            'weight' => (float) $s['weight'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Promotions here, latest first: the positions, never the pay.
     *
     * @return list<array<string, mixed>>
     */
    private function promotions(Employee $employee): array
    {
        return EmployeePromotion::query()
            ->where('employee_id', $employee->id)
            ->with(['fromPosition:id,title', 'toPosition:id,title'])
            ->orderByDesc('effective_date')
            ->limit(5)
            ->get()
            ->map(fn (EmployeePromotion $p): array => [
                'effective_date' => $p->effective_date?->toDateString(),
                'from' => $p->fromPosition?->title,
                'to' => $p->toPosition?->title,
            ])
            ->all();
    }

    /**
     * Training: what was completed in the last year, and what is under way.
     *
     * @return array<string, mixed>
     */
    private function training(Employee $employee, CarbonImmutable $today): array
    {
        $enrollments = TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['requested', 'enrolled', 'completed'])
            ->with('program:id,name')
            ->latest('id')
            ->get();

        $completed = $enrollments
            ->where('status', 'completed')
            ->filter(fn (TrainingEnrollment $e): bool => $e->completed_at !== null && $e->completed_at->gte($today->subYear()));

        return [
            'completed_12m' => $completed->count(),
            'recent' => $completed
                ->sortByDesc('completed_at')
                ->take(3)
                ->map(fn (TrainingEnrollment $e): array => [
                    'program' => $e->program?->name ?? 'Training',
                    'completed_at' => $e->completed_at?->toDateString(),
                ])
                ->values()
                ->all(),
            'current' => $enrollments
                ->whereIn('status', ['requested', 'enrolled'])
                ->take(3)
                ->map(fn (TrainingEnrollment $e): array => [
                    'program' => $e->program?->name ?? 'Training',
                    'status' => $e->status,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Awards over the last two years, latest first.
     *
     * @return list<array<string, mixed>>
     */
    private function awards(Employee $employee, CarbonImmutable $today): array
    {
        return EmployeeAward::query()
            ->where('employee_id', $employee->id)
            ->where('awarded_on', '>=', $today->subYears(2)->toDateString())
            ->with('awardType:id,name')
            ->orderByDesc('awarded_on')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeAward $a): array => [
                'type' => $a->awardType?->name ?? 'Award',
                'awarded_on' => $a->awarded_on?->toDateString(),
            ])
            ->all();
    }
}
