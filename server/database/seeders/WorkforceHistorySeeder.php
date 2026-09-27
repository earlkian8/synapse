<?php

namespace Database\Seeders;

use App\Models\AttritionRiskRun;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePromotion;
use App\Models\EvaluationPeriod;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceScore;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Ml\AttritionRiskAssessor;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\ScoreResult;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seven years of workforce history for the demo company — the record model
 * graduation (ADR 0046) learns from, in enough volume that every requirement on
 * all three predictive surfaces is met, and with patterns of its own so a model
 * trained on it can pass its check against the general model.
 *
 * The company is simulated month by month from January 2019 to today: about 120
 * people at any time, hiring to replace whoever leaves. Everyone already on the
 * roster (the {@see OrganizationSeeder} team) is part of that history — they never
 * leave, and their FY 2025 appraisal from {@see PerformanceSeeder} is kept as it is.
 * What the simulation writes, all through the real models:
 *
 *  - **Appraisals**, FY 2019 to FY 2025, every 31 December for everyone hired
 *    before October — full scorecards on the company's own framework, laid out by
 *    {@see EvaluationOpener::lines()} and scored by {@see PerformanceScorer}, so a
 *    result is derived exactly as the app derives one.
 *  - **Promotions**, decided on each appraisal and effective in the spring after it,
 *    with a salary increase (and often a new role in the department).
 *  - **Departures** through completed offboarding cases — mostly resignations, with
 *    terminations, contract ends and retirements.
 *  - **A risk assessment every March and September**, September 2019 to September
 *    2025, storing each person's record as it stood that day. The stored inputs are the record; the scores on these
 *    seeded runs are illustrative (the run says so), since the inference service is
 *    not called while seeding.
 *
 * The company's own patterns — the ones its models learn and the general ones miss:
 *
 *  - **Promotion** is more frequent than in the general model's workforce (about one
 *    appraisal in five is followed by one), and follows both the level and the
 *    improvement of the latest appraisal.
 *  - **Ratings fall back**: this company's appraisals are strict, so ratings drift
 *    down about a point and a half a cycle towards each person's own level — where
 *    the general model's rise.
 *  - **Resignations follow burnout and disengagement**: heavy overtime above all
 *    (which the survey behind the general model found irrelevant), then absences and
 *    late arrivals, and a long wait for promotion.
 *
 * Deterministic (its own seeded generator) and idempotent (skipped once FY 2019
 * exists). Runs after {@see PerformanceSeeder}, which it needs the frameworks and FY
 * 2025 appraisals of, and before the seeders that fill the current team's
 * attendance, leave and so on, so the people still here get those too.
 */
class WorkforceHistorySeeder extends Seeder
{
    private const SEED = 20260927;

    private const START = '2019-01-01';

    /** Appraisal cycles: FY 2019 to FY 2025 (FY 2025 is {@see PerformanceSeeder}'s period). */
    private const FIRST_CYCLE = 2019;

    private const LAST_CYCLE = 2025;

    /** A risk assessment on the 15th of March and September, from the first to the last of these. */
    private const FIRST_ASSESSMENT = '2019-09-15';

    private const LAST_ASSESSMENT = '2025-09-15';

    /** People on the roster at any time. */
    private const HEADCOUNT = 120;

    private Randomizer $random;

    private CarbonImmutable $today;

    /**
     * Everyone in the history, keyed by a running number.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $people = [];

    /** @var array<int, list<int>> department id => its position ids */
    private array $positions = [];

    /** @var array<string, array{template: ReviewTemplate, lines: list<array<string, mixed>>, bands: array<int, array<string, mixed>>}> */
    private array $frameworks = [];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        if (EvaluationPeriod::query()->where('name', 'FY '.self::FIRST_CYCLE.' Annual Review')->exists()) {
            return;
        }

        if (! $this->prepare()) {
            return;
        }

        $this->random = new Randomizer(new Mt19937(self::SEED));
        $this->today = CarbonImmutable::today();

        $this->joinTheCurrentTeam();
        $this->openingRoster();
        $this->simulate();

        DB::transaction(fn () => $this->persist());
    }

    // ── Setup ────────────────────────────────────────────────────────────────

    /**
     * The departments, positions and frameworks the history is written against.
     * Nothing to do without them (the organisation and performance seeders make
     * them).
     */
    private function prepare(): bool
    {
        foreach (Department::query()->with('positions:id,department_id')->get() as $department) {
            if ($department->positions->isNotEmpty()) {
                $this->positions[$department->id] = $department->positions->pluck('id')->all();
            }
        }

        $opener = app(EvaluationOpener::class);

        foreach (['staff' => 'Individual Contributor Review', 'leadership' => 'People Leader Review'] as $key => $name) {
            $template = ReviewTemplate::query()->where('name', $name)->first();

            if ($template !== null) {
                $this->frameworks[$key] = ['template' => $template, 'lines' => $opener->lines($template), 'bands' => $template->bandList()];
            }
        }

        return $this->positions !== [] && isset($this->frameworks['staff']);
    }

    /**
     * The people already on the roster are part of the history: they stay, and an
     * FY 2025 appraisal they already have is kept, with the years before it
     * written to lead up to it.
     */
    private function joinTheCurrentTeam(): void
    {
        $fy2025 = EvaluationPeriod::query()->where('name', 'FY 2025 Annual Review')->value('id');
        $leadership = $this->frameworks['leadership']['template']->id ?? null;

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereNotNull('date_hired')
            ->with(['performanceEvaluations' => fn ($query) => $query->where('evaluation_period_id', $fy2025)])
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $appraisal = $employee->performanceEvaluations->first();
            $kept = $appraisal !== null && in_array($appraisal->status, ['submitted', 'acknowledged'], true)
                ? (float) $appraisal->overall_percent
                : null;

            $person = $this->newPerson(
                hired: CarbonImmutable::instance($employee->date_hired)->startOfDay(),
                level: $kept !== null ? ($kept - 66) / 8 : $this->normal(),
            );
            $person['employee'] = $employee;
            $person['department_id'] = $employee->department_id ?? array_key_first($this->positions);
            $person['position_id'] = $employee->position_id;
            $person['type'] = $employee->employment_type ?? 'regular';
            $person['salary'] = (float) ($employee->basic_salary ?: 30000);
            $person['framework'] = $appraisal !== null && $appraisal->review_template_id === $leadership ? 'leadership' : 'staff';
            // FY 2025 is theirs already (or deliberately skipped by the performance
            // seeder); the years before it lead up to what they got.
            $person['kept_2025'] = $kept;
            $person['skip'] = [self::LAST_CYCLE => true];
            $person['targets'] = $this->leadUpTo($person, $kept);

            $this->people[] = $person;
        }
    }

    /**
     * Ratings for a current employee's years before FY 2025, worked backwards from
     * the FY 2025 result so the step into it follows the company's pattern.
     *
     * @param  array<string, mixed>  $person
     * @return array<int, float>
     */
    private function leadUpTo(array $person, ?float $kept): array
    {
        if ($kept === null) {
            return [];
        }

        $targets = [];
        $next = $kept;

        for ($year = self::LAST_CYCLE - 1; $year >= self::FIRST_CYCLE; $year--) {
            if (! $this->appraisable($person, $year)) {
                break;
            }

            // The inverse of the forward step (next = r − 1 + 0.35·(level − r)).
            $next = $this->clamp(($next + 1 - 0.35 * $this->personalLevel($person)) / 0.65 + 3 * $this->normal(), 35, 99);
            $targets[$year] = $next;
        }

        return $targets;
    }

    /**
     * The company as it stood on 1 January 2019: a full roster hired over the
     * previous years, some of them promoted before appraisals were on record.
     */
    private function openingRoster(): void
    {
        $start = CarbonImmutable::parse(self::START);
        $current = count(array_filter($this->people, fn (array $p): bool => $p['hired']->lte($start)));

        for ($i = $current; $i < self::HEADCOUNT; $i++) {
            // Tenure at the start: most a few years, a long tail of old hands.
            $years = min(16.0, -3.2 * log(max(1e-6, $this->random->getFloat(0, 1))));
            $hired = $start->subDays((int) round($years * 365.25) + 1);
            $person = $this->hire($hired);

            if ($years >= 1.5 && $this->chance(1 - exp(-$years / 5))) {
                // A promotion from before the appraisal history began.
                $this->promote($person, $hired->addDays($this->random->getInt(365, (int) ($years * 365.25) - 1)));
            }

            $this->people[] = $person;
        }
    }

    // ── The simulation ───────────────────────────────────────────────────────

    private function simulate(): void
    {
        for ($month = CarbonImmutable::parse(self::START); $month->lte($this->today); $month = $month->addMonth()) {
            if ($month->month === 1) {
                $this->drift();
            }

            $this->departures($month);
            $this->promotionsTakingEffect($month);
            $this->hiring($month);

            if (in_array($month->month, [3, 9], true)
                && $month->setDay(15)->betweenIncluded(CarbonImmutable::parse(self::FIRST_ASSESSMENT), CarbonImmutable::parse(self::LAST_ASSESSMENT))) {
                $this->assess($month->setDay(15));
            }

            if ($month->month === 12 && $month->year >= self::FIRST_CYCLE && $month->year <= self::LAST_CYCLE) {
                $this->appraise($month->year);
            }
        }
    }

    /** Engagement and workload move a little each year, and hold. */
    private function drift(): void
    {
        foreach ($this->people as &$person) {
            $person['engagement'] = 0.85 * $person['engagement'] + 0.5 * $this->normal();
            $person['load'] = 0.9 * $person['load'] + 0.45 * $this->normal();
        }
    }

    /**
     * Who leaves this month. The history's own people only — the current team
     * stays. Resignation is driven by burnout (workload), disengagement and a long
     * wait for promotion; terminations by disengagement; contracts run out.
     */
    private function departures(CarbonImmutable $month): void
    {
        foreach ($this->people as &$person) {
            if ($person['employee'] !== null || $person['left'] !== null || $person['hired']->gt($month->endOfMonth())) {
                continue;
            }

            $tenure = $this->years($person['hired'], $month);
            $waiting = $this->sincePromotion($person, $month);
            $type = $this->typeAt($person, $month);

            $resign = $this->sigmoid(-1.5
                + 1.3 * $person['load']
                + 0.9 * $person['engagement']
                + ($waiting >= 3 ? 0.7 : 0.0)
                + ($tenure < 1 ? 0.3 : 0.0)
                + (in_array($type, ['contractual', 'probationary'], true) ? 0.2 : 0.0));

            $risks = [
                'resignation' => $resign,
                'termination' => 0.012 + 0.025 * max(0.0, $person['engagement']),
                'end_of_contract' => $type === 'contractual' && $tenure >= 1 ? 0.3 : 0.0,
                'retirement' => $tenure >= 15 ? 0.06 : 0.0,
            ];

            foreach ($risks as $exit => $annual) {
                if ($annual > 0 && $this->chance(1 - (1 - $annual) ** (1 / 12))) {
                    $earliest = max(1, $person['hired']->isSameMonth($month) ? $person['hired']->day + 1 : 1);
                    $last = $month->setDay($this->random->getInt(min($earliest, $month->daysInMonth), $month->daysInMonth));
                    $person['left'] = $last->gt($this->today) ? $this->today : $last;
                    $person['exit'] = $exit;
                    break;
                }
            }
        }
    }

    /** Promotions decided on last year's appraisals take effect in the spring. */
    private function promotionsTakingEffect(CarbonImmutable $month): void
    {
        foreach ($this->people as &$person) {
            $due = $person['pending'];

            if ($due === null || ! $due->isSameMonth($month)) {
                continue;
            }

            $person['pending'] = null;

            if ($due->lte($this->today) && ($person['left'] === null || $person['left']->gt($due))) {
                $this->promote($person, $due);
            }
        }
    }

    /** Replace whoever left, keeping the roster at about {@see HEADCOUNT}. */
    private function hiring(CarbonImmutable $month): void
    {
        $end = $month->endOfMonth();
        $onRoster = count(array_filter($this->people, fn (array $p): bool => $this->employedOn($p, $end)));
        $openings = self::HEADCOUNT + $this->random->getInt(-2, 2) - $onRoster;

        for ($i = 0; $i < $openings; $i++) {
            $day = $month->setDay($this->random->getInt(1, $month->daysInMonth));

            if ($day->gt($this->today)) {
                return;
            }

            $this->people[] = $this->hire($day);
        }
    }

    /**
     * A risk assessment (March and September): everyone on the roster that day, with the
     * attendance of the 90 days before it drawn from how engaged they are and how
     * much overtime their role piles on.
     */
    private function assess(CarbonImmutable $day): void
    {
        if ($day->gt($this->today)) {
            return;
        }

        foreach ($this->people as &$person) {
            if (! $this->employedOn($person, $day)) {
                continue;
            }

            $d = $person['engagement'];
            $partTime = $this->typeAt($person, $day) === 'part_time';

            $person['snapshots'][] = [
                'at' => $day,
                'absences' => $this->poisson(exp(0.6 + 0.7 * $d)),
                'lates' => $this->poisson(exp(1.2 + 0.7 * $d)),
                'overtime' => round(max(0.0, ($partTime ? 0.3 : 1.0) * (20 + 18 * $person['load'] - 2 * $d + 5 * $this->normal())), 2),
            ];
        }
    }

    /**
     * The 31 December appraisal: everyone on the roster who joined before October.
     * A rating falls back towards the person's own level; disengagement costs a
     * little. The promotion that follows it is decided on its level and on how
     * much it improved on the one before.
     */
    private function appraise(int $year): void
    {
        $day = CarbonImmutable::create($year, 12, 31);

        foreach ($this->people as &$person) {
            if (! $this->employedOn($person, $day) || ! $this->appraisable($person, $year)) {
                continue;
            }

            $previous = $person['ratings'] === [] ? null : end($person['ratings'])['percent'];

            if (isset($person['skip'][$year])) {
                // A current employee's FY 2025 is already on record (or was skipped).
                $percent = $year === self::LAST_CYCLE ? $person['kept_2025'] : null;
            } else {
                $target = $person['targets'][$year] ?? ($previous === null
                    ? $this->clamp(64 + 8 * $person['level'] + 5 * $this->normal(), 35, 98)
                    : $this->clamp($previous - 1 + 0.35 * ($this->personalLevel($person) - $previous)
                        - 0.8 * max(0.0, $person['engagement']) + 3.5 * $this->normal(), 30, 99));
                $scorecard = $this->scorecard($person['framework'], $target);
                $percent = $scorecard['result']->percent;
                $person['ratings'][$year] = ['percent' => $percent, 'scorecard' => $scorecard];
            }

            if ($percent === null) {
                continue;
            }

            if ($year === self::LAST_CYCLE && isset($person['skip'][$year])) {
                $person['ratings'][$year] = ['percent' => $percent, 'scorecard' => null];
            }

            $change = $previous === null ? 0.0 : $percent - $previous;

            if ($this->chance($this->sigmoid(-1.2 + 0.085 * ($percent - 68) + 0.14 * $change))) {
                $person['pending'] = CarbonImmutable::create($year + 1, 2, 1)->addDays($this->random->getInt(0, 88));
            }
        }
    }

    // ── People ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function newPerson(CarbonImmutable $hired, float $level): array
    {
        return [
            'employee' => null,
            'hired' => $hired,
            'left' => null,
            'exit' => null,
            'department_id' => null,
            'position_id' => null,
            'type' => 'regular',
            'salary' => 0.0,
            'framework' => 'staff',
            'level' => $level,
            'engagement' => $this->normal(),
            // How much overtime the person's role piles on them — persistent.
            'load' => $this->normal(),
            'ratings' => [],
            'targets' => [],
            'skip' => [],
            'kept_2025' => null,
            'pending' => null,
            'promotions' => [],
            'snapshots' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hire(CarbonImmutable $hired): array
    {
        $person = $this->newPerson($hired, $this->normal());
        $departments = array_keys($this->positions);
        $department = $departments[$this->random->getInt(0, count($departments) - 1)];
        $person['department_id'] = $department;
        $person['position_id'] = $this->positions[$department][$this->random->getInt(0, count($this->positions[$department]) - 1)];
        $person['type'] = $this->pick(['probationary' => 60, 'regular' => 20, 'contractual' => 15, 'part_time' => 5]);
        $person['salary'] = round($this->clamp(exp(log(27000) + 0.28 * $this->normal()), 15000, 75000), -2);

        return $person;
    }

    /**
     * Record a promotion on `$on`: a raise, and in half the cases a new role in the
     * same department.
     *
     * @param  array<string, mixed>  $person
     */
    private function promote(array &$person, CarbonImmutable $on): void
    {
        $from = $person['salary'];
        $to = round($from * $this->random->getFloat(1.08, 1.16), -2);
        $fromPosition = $person['position_id'];
        $roles = $this->positions[$person['department_id']] ?? [];

        if (count($roles) > 1 && $this->chance(0.5)) {
            $others = array_values(array_diff($roles, [$fromPosition]));
            $person['position_id'] = $others[$this->random->getInt(0, count($others) - 1)];
        }

        $person['promotions'][] = [
            'on' => $on,
            'from_salary' => $from,
            'to_salary' => $to,
            'from_position' => $fromPosition,
            'to_position' => $person['position_id'],
        ];
        $person['salary'] = $to;
        // A promotion re-engages.
        $person['engagement'] -= 0.3;
    }

    /** @param array<string, mixed> $person */
    private function employedOn(array $person, CarbonImmutable $day): bool
    {
        return $person['hired']->lte($day) && ($person['left'] === null || $person['left']->gt($day));
    }

    /**
     * Appraised in a cycle when on the roster before October of it.
     *
     * @param  array<string, mixed>  $person
     */
    private function appraisable(array $person, int $year): bool
    {
        return $person['hired']->lt(CarbonImmutable::create($year, 10, 1));
    }

    /**
     * The level a person's ratings settle at.
     *
     * @param  array<string, mixed>  $person
     */
    private function personalLevel(array $person): float
    {
        return 66 + 4 * $person['level'];
    }

    /**
     * Employment type on a date: probationary hires are regular after six months.
     *
     * @param  array<string, mixed>  $person
     */
    private function typeAt(array $person, CarbonImmutable $day): string
    {
        return $person['type'] === 'probationary' && $person['hired']->addMonths(6)->lte($day) ? 'regular' : $person['type'];
    }

    /**
     * Years since the last promotion on `$day` — the whole tenure for someone never
     * promoted, as the attrition model reads it.
     *
     * @param  array<string, mixed>  $person
     */
    private function sincePromotion(array $person, CarbonImmutable $day): float
    {
        $last = null;

        foreach ($person['promotions'] as $promotion) {
            if ($promotion['on']->lte($day)) {
                $last = $promotion['on'];
            }
        }

        return $this->years($last ?? $person['hired'], $day);
    }

    // ── Scorecards ───────────────────────────────────────────────────────────

    /**
     * A full scorecard on `$framework` rated around `$target` %: every line on its
     * own scale, at a value that scale can take, then scored the way the app
     * scores one.
     *
     * @return array{lines: list<array<string, mixed>>, result: ScoreResult}
     */
    private function scorecard(string $framework, float $target): array
    {
        $lines = [];

        foreach ($this->frameworks[$framework]['lines'] as $line) {
            $min = (float) $line['scale_min'];
            $max = (float) $line['scale_max'];
            $position = $this->clamp($target / 100 + 0.07 * $this->normal(), 0, 1);
            $line['score'] = $this->snap($min + ($max - $min) * $position, $line);
            $lines[] = $line;
        }

        return ['lines' => $lines, 'result' => app(PerformanceScorer::class)->score($lines, $this->frameworks[$framework]['bands'])];
    }

    /**
     * Snap a raw rating onto a value the line's scale can take.
     *
     * @param  array<string, mixed>  $line
     */
    private function snap(float $raw, array $line): float
    {
        $levels = $line['scale_levels'] ?? null;

        if (is_array($levels) && $levels !== []) {
            $values = array_map(fn (array $level): float => (float) $level['value'], $levels);
            usort($values, fn (float $a, float $b): int => abs($a - $raw) <=> abs($b - $raw));

            return $values[0];
        }

        return round($raw);
    }

    // ── Writing it down ──────────────────────────────────────────────────────

    private function persist(): void
    {
        $approver = User::query()->orderBy('id')->value('id');
        $schedule = WorkSchedule::query()->where('name', 'Day Shift')->value('id');
        $periods = $this->periods();

        foreach ($this->people as &$person) {
            if ($person['employee'] === null) {
                $person['employee'] = $this->createEmployee($person, $schedule);
            }
        }
        unset($person);

        foreach ($this->people as $person) {
            $this->writePromotions($person, $approver);
            $this->writeAppraisals($person, $periods, $approver);
        }

        $this->writeAssessments($approver);
    }

    /**
     * FY 2019 to FY 2025, closed. FY 2025 is the performance seeder's own.
     *
     * @return array<int, EvaluationPeriod>
     */
    private function periods(): array
    {
        $periods = [];

        for ($year = self::FIRST_CYCLE; $year <= self::LAST_CYCLE; $year++) {
            $periods[$year] = EvaluationPeriod::firstOrCreate(
                ['name' => "FY {$year} Annual Review"],
                ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31", 'status' => 'closed'],
            );
        }

        return $periods;
    }

    /** @param array<string, mixed> $person */
    private function createEmployee(array $person, ?int $schedule): Employee
    {
        $left = $person['left'];
        $type = $this->typeAt($person, $left ?? $this->today);

        $employee = Employee::factory()->create([
            'department_id' => $person['department_id'],
            'position_id' => $person['position_id'],
            'work_schedule_id' => $schedule,
            'employment_type' => $type,
            'employment_status' => $left === null ? 'active' : OffboardingCase::EMPLOYMENT_STATUS_ON_COMPLETE[$person['exit']],
            'date_hired' => $person['hired']->toDateString(),
            'date_regularized' => $type === 'regular' ? $person['hired']->addMonths(6)->toDateString() : null,
            'basic_salary' => $person['salary'],
            'suffix' => null,
        ]);

        if ($left !== null) {
            OffboardingCase::create([
                'employee_id' => $employee->id,
                'type' => $person['exit'],
                'notice_date' => $left->subDays($this->random->getInt(14, 30))->max($person['hired'])->toDateString(),
                'last_working_day' => $left->toDateString(),
                'reason' => match ($person['exit']) {
                    'termination' => 'Separation following repeated attendance and performance concerns.',
                    'retirement' => 'Retired after long service.',
                    'end_of_contract' => 'Fixed-term contract reached its end date.',
                    default => $this->pick([
                        'Accepted a role with better pay elsewhere.' => 3,
                        'Moving to another city.' => 1,
                        'Career change.' => 1,
                        'Personal and family reasons.' => 1,
                    ]),
                },
                'status' => 'completed',
                'completed_at' => $left->setTime(17, 0),
            ]);
        }

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function writePromotions(array $person, ?int $approver): void
    {
        /** @var Employee $employee */
        $employee = $person['employee'];
        $scale = $this->salaryScale($person);

        foreach ($person['promotions'] as $promotion) {
            EmployeePromotion::create([
                'employee_id' => $employee->id,
                'from_position_id' => $promotion['from_position'],
                'to_position_id' => $promotion['to_position'],
                'from_salary' => round($promotion['from_salary'] * $scale, 2),
                'to_salary' => round($promotion['to_salary'] * $scale, 2),
                'effective_date' => $promotion['on']->toDateString(),
                'reason' => $promotion['from_position'] === $promotion['to_position']
                    ? 'Promoted to a senior grade in the role on the strength of the latest appraisal.'
                    : 'Promoted into the role on the strength of the latest appraisal.',
                'approved_by' => $approver,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<int, EvaluationPeriod>  $periods
     */
    private function writeAppraisals(array $person, array $periods, ?int $evaluator): void
    {
        $framework = $this->frameworks[$person['framework']] ?? $this->frameworks['staff'];
        $rows = [];

        foreach ($person['ratings'] as $year => $rating) {
            if ($rating['scorecard'] === null) {
                continue;
            }

            $submitted = CarbonImmutable::create($year + 1, 1, 10)->addDays($this->random->getInt(0, 14));
            $evaluation = new PerformanceEvaluation([
                'employee_id' => $person['employee']->id,
                'evaluation_period_id' => $periods[$year]->id,
                'review_template_id' => $framework['template']->id,
                'template_name' => $framework['template']->name,
                'template_sections' => $framework['template']->sectionList(),
                'template_bands' => $framework['bands'],
                'result_display' => $framework['template']->result_display,
                'evaluator_id' => $evaluator,
                'status' => 'acknowledged',
                'submitted_at' => $submitted,
                'acknowledged_at' => $submitted->addDays($this->random->getInt(1, 7)),
                'remarks' => $this->remarkFor($rating['percent']),
            ]);
            $evaluation->applyResult($rating['scorecard']['result']);
            $evaluation->save();

            foreach ($rating['scorecard']['lines'] as $line) {
                $rows[] = [
                    ...$line,
                    'organization_id' => $evaluation->organization_id,
                    'performance_evaluation_id' => $evaluation->id,
                    'scale_levels' => $line['scale_levels'] === null ? null : json_encode($line['scale_levels']),
                    'created_at' => $submitted,
                    'updated_at' => $submitted,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PerformanceScore::insert($chunk);
        }
    }

    private function remarkFor(float $percent): string
    {
        return match (true) {
            $percent >= 85 => 'An outstanding cycle — delivered well beyond what was committed.',
            $percent >= 70 => 'Solid contributions through the cycle; keep building on the strengths.',
            $percent >= 55 => 'Met the core of the role; agreed a plan for the areas still developing.',
            default => 'A difficult cycle. A support plan is in place for the next one.',
        };
    }

    /**
     * One stored assessment run per March and September, holding everyone's record
     * as it stood that day — the inputs the attrition model reads, exactly as the
     * assessor would have sent them.
     */
    private function writeAssessments(?int $generatedBy): void
    {
        $days = collect($this->people)->pluck('snapshots')->flatten(1)->pluck('at')
            ->unique(fn (CarbonImmutable $d): string => $d->toDateString())->sort()->values();

        foreach ($days as $day) {
            $rows = [];

            foreach ($this->people as $person) {
                foreach ($person['snapshots'] as $snapshot) {
                    if ($snapshot['at']->equalTo($day)) {
                        $rows[] = $this->scoreRow($person, $snapshot);
                    }
                }
            }

            if ($rows === []) {
                continue;
            }

            $tiers = array_count_values(array_column($rows, 'tier'));
            $run = AttritionRiskRun::create([
                'generated_by' => $generatedBy,
                'status' => 'completed',
                'model_version' => 'seeded history (illustrative scores)',
                'employees_scored' => count($rows),
                'high_count' => $tiers['high'] ?? 0,
                'medium_count' => $tiers['medium'] ?? 0,
                'low_count' => $tiers['low'] ?? 0,
                'average_score' => round(array_sum(array_column($rows, 'score')) / count($rows), 2),
                'average_confidence' => 1.0,
                'note' => 'Seeded demo history: each stored input is the person’s record on the day; the scores are illustrative.',
            ]);
            $run->forceFill(['created_at' => $day->setTime(9, 0), 'updated_at' => $day->setTime(9, 0)])->save();
            $run->scores()->createMany($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function scoreRow(array $person, array $snapshot): array
    {
        $at = $snapshot['at'];
        $tenure = round($this->years($person['hired'], $at), 2);
        $promoted = array_filter($person['promotions'], fn (array $p): bool => $p['on']->lte($at));
        // Pay on the day: the last raise before it, else the starting salary.
        $salary = round(($promoted === [] ? ($person['promotions'][0]['from_salary'] ?? $person['salary']) : end($promoted)['to_salary'])
            * $this->salaryScale($person), 2);

        $features = [
            'employment_type' => $this->typeAt($person, $at),
            'tenure_years' => $tenure,
            'monthly_salary' => $salary,
            'ever_promoted' => $promoted === [] ? 0 : 1,
            'years_since_promotion' => round($this->sincePromotion($person, $at), 2),
            'absences_90d' => $snapshot['absences'],
            'lates_90d' => $snapshot['lates'],
            'overtime_hours_90d' => $snapshot['overtime'],
        ];

        // Illustrative only — the shape of the general model's reading (new hires
        // and low pay first), not a call to it.
        $probability = $this->sigmoid(-1.0 + ($tenure < 1 ? 0.9 : 0.0) + ($tenure >= 6 && $tenure <= 10 ? 0.4 : 0.0)
            - 0.5 * log(max(1.0, $salary) / 25000) + ($features['years_since_promotion'] > 5 ? 0.25 : 0.0));

        return [
            'employee_id' => $person['employee']->id,
            'probability' => round($probability, 5),
            'score' => round($probability * 100, 2),
            'tier' => $probability >= 0.66 ? 'high' : ($probability >= 0.33 ? 'medium' : 'low'),
            'confidence' => round(count(array_intersect_key($features, array_flip(AttritionRiskAssessor::KEY_FEATURES))) / count(AttritionRiskAssessor::KEY_FEATURES), 3),
            'factors' => null,
            'features' => $features,
        ];
    }

    /**
     * What the simulated pay is multiplied by to be the recorded pay. A current
     * employee's salary is on record, so their history's raises lead up to it; for
     * everyone else the simulated pay is the record (the factor is 1).
     *
     * @param  array<string, mixed>  $person
     */
    private function salaryScale(array $person): float
    {
        $recorded = (float) ($person['employee']?->basic_salary ?? 0);

        return $recorded > 0 && $person['salary'] > 0 ? $recorded / $person['salary'] : 1.0;
    }

    // ── Chance ───────────────────────────────────────────────────────────────

    private function normal(): float
    {
        // Box–Muller.
        $u = max(1e-12, $this->random->getFloat(0, 1));

        return sqrt(-2 * log($u)) * cos(2 * M_PI * $this->random->getFloat(0, 1));
    }

    private function chance(float $probability): bool
    {
        return $this->random->getFloat(0, 1) < $probability;
    }

    private function poisson(float $lambda): int
    {
        // Knuth; λ stays small here.
        $limit = exp(-$lambda);
        $k = 0;
        $p = 1.0;

        do {
            $k++;
            $p *= $this->random->getFloat(0, 1);
        } while ($p > $limit);

        return $k - 1;
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function pick(array $weights): string
    {
        $roll = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-$x));
    }

    private function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }

    private function years(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return max(0.0, $from->diffInDays($to) / 365.25);
    }
}
