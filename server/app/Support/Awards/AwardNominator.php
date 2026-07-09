<?php

namespace App\Support\Awards;

use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\TrainingEnrollment;
use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single source of truth for award **nominations** — for every active award
 * type, who deserves it most right now. Pure, deterministic math over the
 * signals the ERP already tracks: appraisal scores (and the ML performance
 * forecast when one exists), attendance over the last 90 days, training
 * completions over the last year, tenure, and how long it has been since the
 * employee was last recognised.
 *
 * Each award type is classified into a **focus profile** from its name and
 * description (attendance awards rank on attendance, service awards on tenure,
 * …), which sets the weight of each signal. Like
 * {@see ApplicantScorer}, a signal with nothing to
 * assess simply drops out and the score is normalised over the signals that
 * remain — so the board works from day one and sharpens as modules fill up.
 *
 * Fairness guard: winning the same award within the last six months zeroes the
 * recognition-gap signal and flags the nominee, so the board spreads
 * recognition instead of crowning the same person twice.
 */
class AwardNominator
{
    /** How many nominees each award type surfaces. */
    public const SHORTLIST = 5;

    /** Months since a same-type win during which a nominee is flagged. */
    public const REPEAT_WINDOW_MONTHS = 6;

    /** Days of attendance history considered. */
    public const ATTENDANCE_WINDOW_DAYS = 90;

    /** Months of training history considered. */
    public const TRAINING_WINDOW_MONTHS = 12;

    /** Months without recognition that earn full recognition-gap marks. */
    public const GAP_RUNWAY_MONTHS = 12;

    /** Years of service that earn full tenure marks. */
    public const TENURE_RUNWAY_YEARS = 10;

    /** Completed trainings that earn full growth marks. */
    public const TRAINING_RUNWAY = 3;

    /**
     * Signal weights per focus profile. Each row sums to 100 before
     * normalisation; absent signals drop out and the rest renormalise.
     *
     * @var array<string, array<string, int>>
     */
    public const PROFILES = [
        'performance' => ['performance' => 40, 'forecast' => 10, 'attendance' => 15, 'training' => 10, 'gap' => 25],
        'attendance' => ['attendance' => 55, 'performance' => 15, 'tenure' => 10, 'gap' => 20],
        'tenure' => ['tenure' => 55, 'performance' => 15, 'attendance' => 10, 'gap' => 20],
        'growth' => ['training' => 50, 'performance' => 20, 'attendance' => 10, 'gap' => 20],
        'allround' => ['performance' => 30, 'attendance' => 15, 'training' => 10, 'tenure' => 10, 'forecast' => 10, 'gap' => 25],
    ];

    /** Human labels + hints per profile, for the board header. */
    public const PROFILE_LABELS = [
        'performance' => ['label' => 'Performance-focused', 'hint' => 'Ranked mainly on appraisal scores and the ML forecast.'],
        'attendance' => ['label' => 'Attendance-focused', 'hint' => 'Ranked mainly on presence and punctuality over the last 90 days.'],
        'tenure' => ['label' => 'Tenure-focused', 'hint' => 'Ranked mainly on years of service.'],
        'growth' => ['label' => 'Growth-focused', 'hint' => 'Ranked mainly on trainings completed in the last year.'],
        'allround' => ['label' => 'All-round', 'hint' => 'A balanced read across performance, attendance, training and tenure.'],
    ];

    /**
     * The nomination board: every active award type with its ranked shortlist.
     *
     * @return list<array<string, mixed>>
     */
    public function board(): array
    {
        $types = AwardType::query()->active()->orderBy('name')->get();

        if ($types->isEmpty()) {
            return [];
        }

        $employees = $this->activeEmployees();
        $signals = $this->signals($employees->pluck('id'));

        return $types
            ->map(fn (AwardType $type): array => $this->nomination($type, $employees, $signals))
            ->values()
            ->all();
    }

    /**
     * Score one employee against one award type — the citation writer's input.
     *
     * @return array<string, mixed>|null
     */
    public function scoreOne(Employee $employee, AwardType $type): ?array
    {
        $signals = $this->signals(collect([$employee->id]));
        $profile = self::profileFor($type->name, $type->description);

        return $this->nominee($employee, $profile, $type->id, $signals);
    }

    /**
     * Classify an award type into a focus profile from its name + description.
     * First match wins; anything unmatched is ranked all-round.
     */
    public static function profileFor(string $name, ?string $description): string
    {
        $haystack = Str::lower($name.' '.($description ?? ''));

        $keywords = [
            'attendance' => ['attendance', 'punctual', 'tardi', 'presence', 'reliab'],
            'tenure' => ['service', 'tenure', 'loyal', 'anniversar', 'milestone', 'veteran'],
            'growth' => ['training', 'learn', 'development', 'growth', 'certif', 'upskill', 'course'],
            'performance' => ['performance', 'month', 'quarter', 'excellen', 'achiev', 'outstand', 'top perform', 'best employee', 'star', 'mvp'],
        ];

        foreach ($keywords as $profile => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $profile;
                }
            }
        }

        return 'allround';
    }

    /** The strong / promising / fair / weak band for a 0–100 fit. */
    public static function band(int $value): string
    {
        return match (true) {
            $value >= 75 => 'strong',
            $value >= 55 => 'promising',
            $value >= 35 => 'fair',
            default => 'weak',
        };
    }

    // ── Board assembly ───────────────────────────────────────────────────────

    /**
     * One award type's entry: its profile and the ranked shortlist.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function nomination(AwardType $type, Collection $employees, array $signals): array
    {
        $profile = self::profileFor($type->name, $type->description);

        $nominees = $employees
            ->map(fn (Employee $employee): ?array => $this->nominee($employee, $profile, $type->id, $signals))
            ->filter(fn (?array $nominee): bool => $nominee !== null && $nominee['score'] > 0)
            ->sortBy([['score', 'desc']])
            ->take(self::SHORTLIST)
            ->values()
            ->map(function (array $nominee, int $index): array {
                $nominee['rank'] = $index + 1;

                return $nominee;
            })
            ->all();

        return [
            'type' => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'color' => $type->color,
            ],
            'profile' => [
                'key' => $profile,
                'label' => self::PROFILE_LABELS[$profile]['label'],
                'hint' => self::PROFILE_LABELS[$profile]['hint'],
            ],
            'nominees' => $nominees,
        ];
    }

    /**
     * Score one employee for one profile: the weighted, normalised fit with its
     * transparent breakdown.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>|null
     */
    private function nominee(Employee $employee, string $profile, int $typeId, array $signals): ?array
    {
        $weights = self::PROFILES[$profile];

        $components = array_values(array_filter([
            isset($weights['performance']) ? $this->performance($employee->id, $weights['performance'], $signals) : null,
            isset($weights['forecast']) ? $this->forecast($employee->id, $weights['forecast'], $signals) : null,
            isset($weights['attendance']) ? $this->attendance($employee->id, $weights['attendance'], $signals) : null,
            isset($weights['training']) ? $this->training($employee->id, $weights['training'], $signals) : null,
            isset($weights['tenure']) ? $this->tenure($employee, $weights['tenure']) : null,
            isset($weights['gap']) ? $this->recognitionGap($employee->id, $typeId, $weights['gap'], $signals) : null,
        ]));

        $available = array_sum(array_column($components, 'max'));

        if ($available <= 0) {
            return null;
        }

        $earned = array_sum(array_column($components, 'points'));
        $score = max(0, min(100, (int) round($earned / $available * 100)));

        $recentWin = $this->monthsSinceTypeWin($employee->id, $typeId, $signals);

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
                'employee_no' => $employee->employee_no,
                'photo' => $employee->photo_url,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
            ],
            'score' => $score,
            'band' => self::band($score),
            'components' => $components,
            'recent_winner' => $recentWin !== null && $recentWin < self::REPEAT_WINDOW_MONTHS,
            'won_months_ago' => $recentWin,
        ];
    }

    // ── Signals ──────────────────────────────────────────────────────────────

    /**
     * Latest appraisal overall (1–5), as a fraction of the scale.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>|null
     */
    private function performance(int $employeeId, int $max, array $signals): ?array
    {
        $overall = $signals['performance'][$employeeId] ?? null;

        if ($overall === null) {
            return null;
        }

        $fraction = max(0, min(1, ($overall - 1) / 4));

        return $this->component('performance', 'Performance', (int) round($fraction * $max), $max,
            'Latest appraisal '.number_format($overall, 2).'/5');
    }

    /**
     * The ML performance forecast (0–100), when one exists.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>|null
     */
    private function forecast(int $employeeId, int $max, array $signals): ?array
    {
        $forecast = $signals['forecast'][$employeeId] ?? null;

        if ($forecast === null) {
            return null;
        }

        $bandLabel = match ($forecast['band']) {
            'exceeds' => 'exceeds',
            'below' => 'below track',
            default => 'on track',
        };

        return $this->component('forecast', 'ML forecast', (int) round($forecast['rating'] / 100 * $max), $max,
            'Forecast '.round($forecast['rating'])."/100 · {$bandLabel}");
    }

    /**
     * Presence over the attendance window: lates and undertime count as partial
     * presence; leave / days off / holidays are excused entirely.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>|null
     */
    private function attendance(int $employeeId, int $max, array $signals): ?array
    {
        $counts = $signals['attendance'][$employeeId] ?? null;

        if ($counts === null) {
            return null;
        }

        $present = $counts['present'] ?? 0;
        $late = $counts['late'] ?? 0;
        $undertime = $counts['undertime'] ?? 0;
        $absent = $counts['absent'] ?? 0;

        $workdays = $present + $late + $undertime + $absent;

        if ($workdays === 0) {
            return null;
        }

        $rate = ($present + 0.75 * ($late + $undertime)) / $workdays;
        $perfect = $absent === 0 && $late === 0 && $undertime === 0;

        $detail = $perfect
            ? 'Perfect attendance (90 days)'
            : round(($workdays - $absent) / $workdays * 100).'% present'.($late > 0 ? " · {$late} late".($late === 1 ? '' : 's') : '');

        return $this->component('attendance', 'Attendance', (int) round($rate * $max), $max, $detail);
    }

    /**
     * Trainings completed over the last year, refined by their average score.
     * Zero completions is a real (zero) signal, not an absent one.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function training(int $employeeId, int $max, array $signals): array
    {
        $row = $signals['training'][$employeeId] ?? null;
        $completed = (int) ($row['count'] ?? 0);
        $avgScore = $row['avg_score'] ?? null;

        if ($completed === 0) {
            return $this->component('training', 'Training', 0, $max, 'No completions in 12 months');
        }

        $base = min($completed / self::TRAINING_RUNWAY, 1);
        $fraction = $avgScore !== null ? $base * 0.7 + ($avgScore / 100) * 0.3 : $base;

        $detail = "{$completed} completed".($avgScore !== null ? ' · avg '.round($avgScore).'%' : '');

        return $this->component('training', 'Training', (int) round($fraction * $max), $max, $detail);
    }

    /**
     * Years of service against the tenure runway.
     *
     * @return array<string, mixed>|null
     */
    private function tenure(Employee $employee, int $max): ?array
    {
        if ($employee->date_hired === null) {
            return null;
        }

        $years = $employee->date_hired->diffInDays(today()) / 365.25;
        $fraction = min($years / self::TENURE_RUNWAY_YEARS, 1);

        $display = $years >= 1 ? round($years, 1).' yrs of service' : 'Under a year of service';

        return $this->component('tenure', 'Tenure', (int) round($fraction * $max), $max, $display);
    }

    /**
     * How overdue the employee is for recognition. A same-type win inside the
     * repeat window zeroes this signal so the board spreads recognition.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function recognitionGap(int $employeeId, int $typeId, int $max, array $signals): array
    {
        $sameType = $this->monthsSinceTypeWin($employeeId, $typeId, $signals);

        if ($sameType !== null && $sameType < self::REPEAT_WINDOW_MONTHS) {
            return $this->component('gap', 'Recognition gap', 0, $max,
                'Won this award '.$this->monthsLabel($sameType).' ago');
        }

        $months = $signals['last_award'][$employeeId] ?? null;

        if ($months === null) {
            return $this->component('gap', 'Recognition gap', $max, $max, 'Never recognised');
        }

        $fraction = min($months / self::GAP_RUNWAY_MONTHS, 1);

        return $this->component('gap', 'Recognition gap', (int) round($fraction * $max), $max,
            'Last recognised '.$this->monthsLabel($months).' ago');
    }

    // ── Batched signal queries ───────────────────────────────────────────────

    /**
     * Every signal for the given employees, batched into one query per source.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<string, mixed>
     */
    private function signals(Collection $employeeIds): array
    {
        return [
            'performance' => $this->performanceByEmployee($employeeIds),
            'forecast' => $this->forecastByEmployee($employeeIds),
            'attendance' => $this->attendanceByEmployee($employeeIds),
            'training' => $this->trainingByEmployee($employeeIds),
            'last_award' => $this->monthsSinceLastAward($employeeIds),
            'type_wins' => $this->monthsSinceTypeWins($employeeIds),
        ];
    }

    /**
     * The latest submitted / acknowledged overall score per employee.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, float>
     */
    private function performanceByEmployee(Collection $employeeIds): array
    {
        // Ordered ascending and keyed, so the last write per employee wins.
        return PerformanceEvaluation::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['submitted', 'acknowledged'])
            ->whereNotNull('overall_score')
            ->orderBy('id')
            ->get(['employee_id', 'overall_score'])
            ->mapWithKeys(fn (PerformanceEvaluation $e): array => [$e->employee_id => (float) $e->overall_score])
            ->all();
    }

    /**
     * The latest ML forecast per employee (rating + band), when any exist.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array{rating: float, band: string}>
     */
    private function forecastByEmployee(Collection $employeeIds): array
    {
        return PerformanceForecast::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->get(['employee_id', 'predicted_rating', 'band'])
            ->mapWithKeys(fn (PerformanceForecast $f): array => [
                $f->employee_id => ['rating' => (float) $f->predicted_rating, 'band' => $f->band],
            ])
            ->all();
    }

    /**
     * Attendance status counts per employee over the window.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array<string, int>>
     */
    private function attendanceByEmployee(Collection $employeeIds): array
    {
        $since = today()->subDays(self::ATTENDANCE_WINDOW_DAYS);

        return DB::table('attendance_records')
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date', '>=', $since->toDateString())
            ->selectRaw('employee_id, status, count(*) as total')
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows): array => $rows->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all())
            ->all();
    }

    /**
     * Completed trainings (count + average score) per employee over the window.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array{count: int, avg_score: float|null}>
     */
    private function trainingByEmployee(Collection $employeeIds): array
    {
        $since = now()->subMonths(self::TRAINING_WINDOW_MONTHS);

        return TrainingEnrollment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->selectRaw('employee_id, count(*) as total, avg(score) as avg_score')
            ->groupBy('employee_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->employee_id => [
                    'count' => (int) $row->total,
                    'avg_score' => $row->avg_score === null ? null : (float) $row->avg_score,
                ],
            ])
            ->all();
    }

    /**
     * Whole months since each employee's most recent award of any type.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, int>
     */
    private function monthsSinceLastAward(Collection $employeeIds): array
    {
        return EmployeeAward::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('awarded_on')
            ->selectRaw('employee_id, max(awarded_on) as last_on')
            ->groupBy('employee_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->employee_id => $this->wholeMonthsSince((string) $row->last_on),
            ])
            ->all();
    }

    /**
     * Whole months since each employee's most recent award, per award type.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array<int, int>> employee → type → months ago
     */
    private function monthsSinceTypeWins(Collection $employeeIds): array
    {
        return EmployeeAward::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('awarded_on')
            ->selectRaw('employee_id, award_type_id, max(awarded_on) as last_on')
            ->groupBy('employee_id', 'award_type_id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows): array => $rows->mapWithKeys(fn ($row): array => [
                (int) $row->award_type_id => $this->wholeMonthsSince((string) $row->last_on),
            ])->all())
            ->all();
    }

    /**
     * Months since this employee last won this specific award type.
     *
     * @param  array<string, mixed>  $signals
     */
    private function monthsSinceTypeWin(int $employeeId, int $typeId, array $signals): ?int
    {
        return $signals['type_wins'][$employeeId][$typeId] ?? null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Active employees with the relations the nominee card renders.
     *
     * @return Collection<int, Employee>
     */
    private function activeEmployees(): Collection
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->with(['department:id,name', 'position:id,title'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'photo', 'date_hired', 'department_id', 'position_id']);
    }

    private function wholeMonthsSince(string $date): int
    {
        return (int) floor(Carbon::parse($date)->diffInDays(today()) / 30.44);
    }

    private function monthsLabel(int $months): string
    {
        return $months < 1 ? 'less than a month' : $months.' month'.($months === 1 ? '' : 's');
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}
     */
    private function component(string $key, string $label, int $points, int $max, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'points' => min($points, $max), 'max' => $max, 'detail' => $detail];
    }
}
