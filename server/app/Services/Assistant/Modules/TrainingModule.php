<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Training\TrainingBudgetRequest;
use App\Http\Requests\Training\TrainingCostRequest;
use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Http\Requests\Training\TrainingProgramRequest;
use App\Http\Requests\Training\TrainingSessionRequest;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrainingAttendance;
use App\Models\TrainingCost;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use App\Support\Training\TrainingBudgets;
use App\Support\Training\TrainingCertificates;
use App\Support\Training\TrainingException;
use App\Support\Training\TrainingSelfService;
use App\Support\Training\TrainingSessions;
use App\Support\Training\TrainingWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

/**
 * Training capability: read the company's training programs and run their
 * rosters.
 *
 * **Reading** answers what L&D and managers ask — "what's running this month?",
 * "how did the Excel course go?", "what has Maria completed?" — from the same
 * aggregates the Training screens show ({@see TrainingProgram::analytics()}).
 * **Doing** creates and edits programs, enrolls people, grades them and takes
 * them off a roster, all through {@see TrainingWorkflow} — the path the screens
 * take — so eligibility, capacity and the completion stamp follow the screens'
 * own rules, and the values are checked against the screens' own validation.
 *
 * Disclosure follows the screens: reading the programs needs `training.view`,
 * and every change `training.manage`. Removing somebody from a roster — their
 * score and remarks go with them — archiving a program and deciding a request
 * to join wait for the user's Confirm (ADR 0049).
 *
 * Since ADRs 0074 / 0075 it also reads and runs sessions and attendance
 * ({@see TrainingSessions}), the certificates training issues
 * ({@see TrainingCertificates}) and what training costs against its budgets
 * ({@see TrainingBudgets}); and, for the person themselves
 * (`training.participate`), their own training, the programs they may join, and
 * joining or leaving one ({@see TrainingSelfService}) — both waiting for Confirm.
 *
 * Programs and people resolve to exactly one or not at all.
 */
class TrainingModule extends Module implements ContributesContext, ContributesTopicContext
{
    /** How many results a list returns. */
    private const MAX_RESULTS = 12;

    /** How many people one enroll call may name. */
    private const MAX_ENROLL = 25;

    /** How many of a person's enrollments their brief lists. */
    private const CONTEXT_ENROLLMENTS = 6;

    /** How many names a program read-out spells out per group. */
    private const MAX_NAMES = 8;

    /** How many people one attendance call may mark. */
    private const MAX_MARKS = 100;

    public function __construct(
        private readonly TrainingWorkflow $workflow,
        private readonly TrainingSelfService $selfService,
        private readonly TrainingSessions $sessions,
        private readonly TrainingBudgets $budgets,
    ) {}

    public function key(): string
    {
        return 'training';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('training.view') || $user->can('training.participate');
    }

    protected function toolMap(): array
    {
        return [
            'find_training_programs' => 'findPrograms',
            'get_training_program' => 'getProgram',
            'find_training_enrollments' => 'findEnrollments',
            'training_summary' => 'summary',
            'create_training_program' => 'createProgram',
            'update_training_program' => 'updateProgram',
            'enroll_in_training' => 'enroll',
            'update_training_enrollment' => 'grade',
            'remove_from_training' => 'removeEnrollment',
            'archive_training_program' => 'archiveProgram',
            'find_training_requests' => 'findRequests',
            'decide_training_request' => 'decideRequest',
            'find_training_sessions' => 'findSessions',
            'schedule_training_session' => 'scheduleSession',
            'record_training_attendance' => 'recordAttendance',
            'find_training_certificates' => 'findCertificates',
            'get_training_budget' => 'readBudget',
            'record_training_cost' => 'recordCost',
            'set_training_budget' => 'setBudget',
            'find_my_training' => 'myTraining',
            'find_open_trainings' => 'openTrainings',
            'join_training' => 'joinTraining',
            'leave_training' => 'leaveTraining',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_training_programs' => 'training.view',
            'get_training_program' => 'training.view',
            'find_training_enrollments' => 'training.view',
            'training_summary' => 'training.view',
            'create_training_program' => 'training.manage',
            'update_training_program' => 'training.manage',
            'enroll_in_training' => 'training.manage',
            'update_training_enrollment' => 'training.manage',
            'remove_from_training' => 'training.manage',
            'archive_training_program' => 'training.manage',
            'find_training_requests' => 'training.view',
            'decide_training_request' => 'training.manage',
            'find_training_sessions' => 'training.view',
            'schedule_training_session' => 'training.manage',
            'record_training_attendance' => 'training.manage',
            'find_training_certificates' => 'training.view',
            'get_training_budget' => 'training.view',
            'record_training_cost' => 'training.manage',
            'set_training_budget' => 'training.manage',
            'find_my_training' => 'training.participate',
            'find_open_trainings' => 'training.participate',
            'join_training' => 'training.participate',
            'leave_training' => 'training.participate',
        ];
    }

    protected function confirmTools(): array
    {
        // A removal takes the person's score and remarks with it; archiving
        // takes a whole program off the screens; a decision on a request tells
        // the person; joining and leaving change the user's own training.
        return ['remove_from_training', 'archive_training_program', 'decide_training_request', 'join_training', 'leave_training'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        // Isolation is a global scope that switches itself off with no tenant
        // bound — the one state in which these queries would see everyone.
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'training.view';

        if ($user->cannot($permission)) {
            return $this->denied(match ($permission) {
                'training.manage' => 'change training',
                'training.participate' => 'take part in training yourself',
                default => 'view training',
            });
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $view = '';

        if ($this->allows($user, 'training.view')) {
            $programs = $this->catalog(
                TrainingProgram::query()->recentFirst()->limit(20)->get(['name', 'start_date', 'end_date'])
                    ->reject(fn (TrainingProgram $p): bool => $p->status() === 'completed')
                    ->map(fn (TrainingProgram $p): string => "{$p->name} ({$p->status()})"),
            );

            $view = <<<TXT

            - find_training_programs lists programs; get_training_program reads one — schedule, who can join, seats, completion rate, average score, attendance, its certificate and budget, and who is at risk or dropped; find_training_enrollments lists a person's (or a program's) enrollments; training_summary reads the whole training picture.
            - find_training_requests lists requests to join waiting for a decision; find_training_sessions lists sessions (by program, or a date range) with the attendance taken; find_training_certificates lists the certificates training issued (by person, or by standing: valid, expiring within 60 days, expired, renewed); get_training_budget reads a year's budget against spend (by category and program), or one program's budget and costs.
              Current programs: {$programs}
            TXT;
        }

        $manage = $this->allows($user, 'training.manage')
            ? <<<'TXT'

            - create_training_program / update_training_program set a program's name, provider, description, dates (YYYY-MM-DD), seat capacity (0 removes the cap on an update), who can join (enrollment_mode: closed = HR enrolls, open = anyone joins while seats last, approval = anyone asks and HR decides), whether completing it earns a certificate and for how many months it is valid (0 = never expires), and its budget in pesos. Turning certificates on issues them to everyone who already completed.
            - enroll_in_training enrolls up to 25 people, by name; inactive and already-enrolled people are skipped and capacity is respected; anyone who asked to join is enrolled on their request.
            - update_training_enrollment sets one person's status, score (0–100) or remarks — mark them completed when they finished; on a program that issues certificates, that issues theirs.
            - schedule_training_session adds a session (day, local start and end times, title, place), optionally repeated daily (or on weekdays) up to a date. record_training_attendance marks people present, late, absent or excused at a program's session on a day (from that day on).
            - record_training_cost records a cost on a program (trainer_fee, venue, materials, travel, meals, certification or other); set_training_budget sets a year's training budget.
            - decide_training_request approves (if a seat is left) or declines (with a note) a request to join; remove_from_training takes someone off a roster (their score, remarks and certificate go with them); archive_training_program archives a program. These wait for the user's confirmation.
            TXT
            : '';

        $participate = $this->allows($user, 'training.participate')
            ? <<<'TXT'

            - For the user's OWN training: find_my_training lists their programs (attendance, score, requests waiting), their sessions coming up and their certificates; find_open_trainings lists the programs they may join or ask to join; join_training joins one (or asks to, when HR approves) and leave_training withdraws their request or gives up their seat before the program starts. Both wait for the user's confirmation.
            TXT
            : '';

        return <<<TXT
        TRAINING — the company's training programs and who is enrolled in each. A program's status (upcoming → ongoing → completed) follows its dates; an enrollment is enrolled → completed or dropped, with an optional 0–100 score, and a request to join is requested → enrolled or declined. Programs meet in dated sessions with attendance; completing one may earn a certificate that expires; costs are recorded against budgets. Money is in pesos.
        - Pass programs by name, and people by name or employee number.{$view}{$manage}{$participate}
        TXT;
    }

    public function tools(User $user): array
    {
        $program = ['type' => 'STRING', 'description' => 'Training program name.'];
        $employee = ['type' => 'STRING', 'description' => 'Employee name or employee number.'];
        $details = [
            'provider' => ['type' => 'STRING', 'description' => 'Who runs it (a vendor, or in-house).'],
            'description' => ['type' => 'STRING', 'description' => 'What the program covers.'],
            'start_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
            'end_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, on or after the start.'],
            'capacity' => ['type' => 'INTEGER', 'description' => 'Seats available.'],
            'enrollment_mode' => ['type' => 'STRING', 'enum' => TrainingProgram::ENROLLMENT_MODES, 'description' => 'closed = HR enrolls; open = anyone joins while seats last; approval = anyone asks, HR decides.'],
            'issues_certificate' => ['type' => 'BOOLEAN', 'description' => 'Whether completing it earns a certificate.'],
            'certificate_validity_months' => ['type' => 'INTEGER', 'description' => 'Months a certificate stays valid; 0 = it never expires.'],
            'budget' => ['type' => 'NUMBER', 'description' => 'What the program may spend, in pesos; 0 removes it.'],
        ];
        $date = ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'];
        $names = ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Names or employee numbers.'];

        return $this->permitted($user, [
            [
                'name' => 'find_training_programs',
                'description' => 'List training programs, optionally by (part of) their name or provider, and by status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the program name or provider.'],
                        'status' => ['type' => 'STRING', 'enum' => TrainingProgram::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'get_training_program',
                'description' => 'Read one program: schedule, provider, seats, completion rate, average score, who is still enrolled after it ended, and who dropped.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $program],
                    'required' => ['program'],
                ],
            ],
            [
                'name' => 'find_training_enrollments',
                'description' => "List enrollments: one person's trainings, or one program's roster, optionally by status.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'program' => $program,
                        'status' => ['type' => 'STRING', 'enum' => TrainingEnrollment::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'training_summary',
                'description' => 'The training picture across the company: programs by status, active enrollments, completions in the last year, and programs that need follow-up.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_training_program',
                'description' => 'Create a training program.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING', 'description' => 'Program name.'], ...$details],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_training_program',
                'description' => "Change a program's name, provider, description, dates, capacity, who can join, its certificate or its budget. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'new_name' => ['type' => 'STRING', 'description' => 'A new name for the program.'],
                        ...$details,
                        'capacity' => ['type' => 'INTEGER', 'description' => 'Seats available; 0 removes the cap.'],
                    ],
                    'required' => ['program'],
                ],
            ],
            [
                'name' => 'enroll_in_training',
                'description' => 'Enroll people into a program. Inactive and already-enrolled people are skipped; capacity is respected.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'employees' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Names or employee numbers, at most 25.'],
                    ],
                    'required' => ['program', 'employees'],
                ],
            ],
            [
                'name' => 'update_training_enrollment',
                'description' => "Set one person's status, score or remarks on a program's roster.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'employee' => $employee,
                        'status' => ['type' => 'STRING', 'enum' => TrainingEnrollment::ROSTER_STATUSES],
                        'score' => ['type' => 'NUMBER', 'description' => 'Completion score, 0–100.'],
                        'remarks' => ['type' => 'STRING', 'description' => 'Notes on how they did.'],
                    ],
                    'required' => ['program', 'employee'],
                ],
            ],
            [
                'name' => 'remove_from_training',
                'description' => "Take one person off a program's roster. Their score and remarks are removed with them.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $program, 'employee' => $employee],
                    'required' => ['program', 'employee'],
                ],
            ],
            [
                'name' => 'archive_training_program',
                'description' => 'Archive a training program. It can be restored from the Training screen.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $program],
                    'required' => ['program'],
                ],
            ],
            [
                'name' => 'find_training_requests',
                'description' => 'List the requests to join a program that wait for a decision, optionally for one program.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['program' => $program]],
            ],
            [
                'name' => 'decide_training_request',
                'description' => "Approve (if a seat is left) or decline someone's request to join a program. They are told.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'employee' => $employee,
                        'decision' => ['type' => 'STRING', 'enum' => ['approve', 'decline']],
                        'note' => ['type' => 'STRING', 'description' => 'Why, when declining; shown to them.'],
                    ],
                    'required' => ['program', 'employee', 'decision'],
                ],
            ],
            [
                'name' => 'find_training_sessions',
                'description' => 'List training sessions — one program\'s, or every program\'s between two dates — with the attendance taken at each.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $program, 'from' => $date, 'to' => $date],
                ],
            ],
            [
                'name' => 'schedule_training_session',
                'description' => "Add a session to a program, inside the program's dates, on the company's clock. Repeat it daily up to a date to add one per day.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'date' => $date,
                        'starts_at' => ['type' => 'STRING', 'description' => 'HH:MM, 24-hour.'],
                        'ends_at' => ['type' => 'STRING', 'description' => 'HH:MM, 24-hour.'],
                        'title' => ['type' => 'STRING'],
                        'location' => ['type' => 'STRING'],
                        'repeat_until' => $date,
                        'weekdays_only' => ['type' => 'BOOLEAN', 'description' => 'With repeat_until: skip Saturdays and Sundays.'],
                    ],
                    'required' => ['program', 'date', 'starts_at', 'ends_at'],
                ],
            ],
            [
                'name' => 'record_training_attendance',
                'description' => "Take attendance at a program's session on a day (from that day on): who was present, late, absent or excused.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'date' => $date,
                        'starts_at' => ['type' => 'STRING', 'description' => 'HH:MM — only when the program has two sessions that day.'],
                        'present' => $names,
                        'late' => $names,
                        'absent' => $names,
                        'excused' => $names,
                        'everyone_else' => ['type' => 'STRING', 'enum' => TrainingAttendance::STATUSES, 'description' => 'Mark everyone not named with this status.'],
                    ],
                    'required' => ['program', 'date'],
                ],
            ],
            [
                'name' => 'find_training_certificates',
                'description' => 'List certificates training issued: one person\'s, or by standing (valid, expiring within 60 days, expired, renewed).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'standing' => ['type' => 'STRING', 'enum' => TrainingCertificates::STANDINGS],
                    ],
                ],
            ],
            [
                'name' => 'get_training_budget',
                'description' => "Read a year's training budget against what was spent (by category and program), or one program's budget and costs.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['year' => ['type' => 'INTEGER'], 'program' => $program],
                ],
            ],
            [
                'name' => 'record_training_cost',
                'description' => 'Record what a program cost.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'program' => $program,
                        'amount' => ['type' => 'NUMBER', 'description' => 'In pesos.'],
                        'category' => ['type' => 'STRING', 'enum' => TrainingCost::CATEGORIES],
                        'description' => ['type' => 'STRING'],
                        'incurred_on' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; today when left out.'],
                    ],
                    'required' => ['program', 'amount', 'category'],
                ],
            ],
            [
                'name' => 'set_training_budget',
                'description' => "Set the company's training budget for a year.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'year' => ['type' => 'INTEGER'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'In pesos.'],
                        'notes' => ['type' => 'STRING'],
                    ],
                    'required' => ['year', 'amount'],
                ],
            ],
            [
                'name' => 'find_my_training',
                'description' => "The user's own training: their programs with attendance and score, requests waiting, sessions coming up, and certificates.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'find_open_trainings',
                'description' => 'Programs the user may join, or ask to join, with seats left and the certificate they earn.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['query' => ['type' => 'STRING', 'description' => 'Part of the program name or provider.']],
                ],
            ],
            [
                'name' => 'join_training',
                'description' => 'Join a program for the user, or ask to join one that HR approves.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['program' => $program], 'required' => ['program']],
            ],
            [
                'name' => 'leave_training',
                'description' => "Withdraw the user's request to join a program, or give up their seat before it starts.",
                'parameters' => ['type' => 'OBJECT', 'properties' => ['program' => $program], 'required' => ['program']],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * A person's training record: their latest enrollments and how they came
     * out of each.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        // No self-service exception: the screens have no "my training" view.
        if ($employee === null || $user->cannot('training.view')) {
            return null;
        }

        $enrollments = TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereHas('program')
            ->with('program:id,name,provider,start_date,end_date')
            ->latest('id')
            ->get();

        if ($enrollments->isEmpty()) {
            return ContextSection::of('Training', ['Not enrolled in any training program.']);
        }

        $completed = $enrollments->where('status', 'completed');
        $scored = $completed->whereNotNull('score');
        $certificates = TrainingCertificates::register()->where('employee_id', $employee->id)->get();
        $renewed = TrainingCertificates::renewedIds($certificates);

        return ContextSection::of('Training', [
            sprintf(
                '%d %s on record: %d completed, %d in progress, %d dropped%s.',
                $enrollments->count(),
                Str::plural('enrollment', $enrollments->count()),
                $completed->count(),
                $enrollments->where('status', 'enrolled')->count(),
                $enrollments->where('status', 'dropped')->count(),
                $scored->isNotEmpty() ? '; average completion score '.$this->number($scored->avg(fn (TrainingEnrollment $e): float => (float) $e->score)) : '',
            ),
            ...$enrollments->take(self::CONTEXT_ENROLLMENTS)
                ->map(fn (TrainingEnrollment $e): string => $this->describeEnrollment($e, withProgram: true))
                ->all(),
            ...$certificates->take(self::CONTEXT_ENROLLMENTS)
                ->map(fn (EmployeeCertification $c): string => "Certificate {$c->certificate_no} ({$c->name}): ".TrainingCertificates::standing($c, isset($renewed[$c->id])).($c->expiry_date ? ', expires '.$c->expiry_date->format('M j, Y') : ', never expires').'.')
                ->all(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'training', 'trainings', 'course', 'courses', 'seminar', 'seminars', 'workshop', 'workshops',
            'learning', 'upskilling', 'certification', 'certifications', 'enrollment', 'enrollments',
            'enrolment', 'enrolments', 'enrolled', 'pagsasanay',
        ];
    }

    /**
     * The training picture: what is running and coming up, how full, how it is
     * going, and which programs need somebody to follow up.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('training.view')) {
            return null;
        }

        $lines = $this->pictureLines();

        return $lines === [] ? ContextSection::of('Training', ['No training programs have been set up yet.']) : ContextSection::of('Training', $lines);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPrograms(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $status = in_array($args['status'] ?? null, TrainingProgram::STATUSES, true) ? $args['status'] : null;
        $like = TrainingProgram::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $programs = $this->withCounts(TrainingProgram::query())
            ->when($query !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', $like, '%'.$this->escapeLike($query).'%')
                ->orWhere('provider', $like, '%'.$this->escapeLike($query).'%')))
            ->recentFirst()
            ->get()
            // Status is derived from the dates, never stored — filter after.
            ->when($status !== null, fn ($c) => $c->filter(fn (TrainingProgram $p): bool => $p->status() === $status))
            ->take(self::MAX_RESULTS);

        $cards = $programs->map(fn (TrainingProgram $p): array => $this->programCard($p, 'find', 'neutral', ucfirst($p->status())))->values()->all();

        return ToolResult::found('Searched training programs', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getProgram(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $program->loadCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->load(['enrollments.employee:id,first_name,middle_name,last_name,suffix']);

        $stats = $program->analytics();
        $atRisk = $stats['at_risk'] > 0 ? $this->namesWith($program->enrollments, 'enrolled') : null;
        $dropped = $stats['dropped'] > 0 ? $this->namesWith($program->enrollments, 'dropped') : null;

        $sessions = $program->sessions()->with('attendances:id,training_session_id,status')->get();
        $held = $sessions->filter(fn (TrainingSession $session): bool => $session->isHeld());
        $attendance = TrainingSessions::rate($held->flatMap(fn (TrainingSession $session) => $session->attendances->pluck('status')));
        $money = TrainingBudgets::forProgram($program);
        $waiting = $program->enrollments->where('status', 'requested')->count();

        $card = $this->programCard($program, 'insight', $stats['at_risk'] > 0 ? 'warning' : 'info', ucfirst($program->status()));
        $card['meta'] = array_values(array_filter([
            $this->seats($program),
            $this->joining($program).($waiting > 0 ? "; {$waiting} ".Str::plural('request', $waiting).' waiting' : ''),
            $this->outcomeLine($stats),
            $stats['average_score'] !== null ? 'Average score '.$this->number($stats['average_score']) : null,
            $sessions->isEmpty()
                ? 'No sessions scheduled'
                : "{$sessions->count()} ".Str::plural('session', $sessions->count()).", {$held->count()} held".($attendance['rate'] !== null ? ", attendance {$attendance['rate']}%" : ''),
            $program->issues_certificate ? 'Earns a certificate, '.($program->certificate_validity_months ? "valid {$program->certificate_validity_months} months" : 'never expires') : null,
            $money['budget'] !== null || $money['spent'] > 0
                ? 'Spent '.TrainingBudgets::money($money['spent']).($money['budget'] !== null ? ' of a '.TrainingBudgets::money($money['budget']).' budget' : ' (no budget set)')
                : null,
            $atRisk !== null ? 'Still enrolled after it ended: '.$atRisk : null,
            $dropped !== null ? 'Dropped: '.$dropped : null,
            filled($program->description) ? 'About: '.Str::limit((string) $program->description, 240) : null,
        ]));

        return ToolResult::found("Read {$program->name}", $this->schedule($program), [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findEnrollments(User $user, array $args): ToolResult
    {
        $employee = null;
        $program = null;

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        if (filled($args['program'] ?? null)) {
            [$program, $error] = $this->locateProgram((string) $args['program']);

            if ($program === null) {
                return ToolResult::error('Looked up the program', $error);
            }
        }

        if ($employee === null && $program === null) {
            return ToolResult::error('Searched enrollments', 'Say whose trainings, or which program.');
        }

        $status = in_array($args['status'] ?? null, TrainingEnrollment::STATUSES, true) ? $args['status'] : null;

        $enrollments = TrainingEnrollment::query()
            ->whereHas('program')
            ->with(['program:id,name,start_date,end_date', 'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo'])
            ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->when($program !== null, fn (Builder $q) => $q->where('training_program_id', $program->id))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $enrollments->map(fn (TrainingEnrollment $e): array => $this->enrollmentCard($e, 'find', 'neutral', ucfirst($e->status)))->all();

        return ToolResult::found('Searched enrollments', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $lines = $this->pictureLines();

        if ($lines === []) {
            return ToolResult::error('Read the training picture', 'No training programs have been set up yet.');
        }

        return ToolResult::found('Read the training picture', null, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Training',
                title: 'Training across the company',
                subtitle: array_shift($lines),
                meta: $lines,
            ),
        ]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createProgram(User $user, array $args): ToolResult
    {
        [$data, $error] = $this->programFields($args, ['name' => $args['name'] ?? null]);

        if ($error !== null) {
            return ToolResult::error('Created the program', $error);
        }

        $clash = $this->resolveId(TrainingProgram::query(), 'name', (string) $data['name']);

        if ($clash !== null) {
            return ToolResult::error('Created the program', 'A program called “'.Str::limit((string) $data['name'], 60).'” already exists.');
        }

        if (($problem = $this->invalid($data, (new TrainingProgramRequest)->rules())) !== null) {
            return ToolResult::error('Created the program', $problem);
        }

        $program = $this->workflow->create($data, ' via assistant');

        return ToolResult::ok(
            "Created {$program->name}",
            $this->schedule($program),
            $this->programCard($program, 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateProgram(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        [$changes, $error] = $this->programFields($args, filled($args['new_name'] ?? null) ? ['name' => $args['new_name']] : []);

        if ($error !== null) {
            return ToolResult::error('Updated the program', $error);
        }

        if ($changes === []) {
            return ToolResult::error('Updated the program', 'Say what to change.');
        }

        // The whole program, as it would be, against the screen's own rules — so
        // moving only the end date is still checked against the start.
        $merged = [
            'name' => $program->name,
            'description' => $program->description,
            'provider' => $program->provider,
            'start_date' => $program->start_date?->toDateString(),
            'end_date' => $program->end_date?->toDateString(),
            'capacity' => $program->capacity,
            'enrollment_mode' => $program->enrollment_mode,
            'issues_certificate' => $program->issues_certificate,
            'certificate_validity_months' => $program->certificate_validity_months,
            'budget' => $program->budget === null ? null : (float) $program->budget,
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new TrainingProgramRequest)->rules())) !== null) {
            return ToolResult::error('Updated the program', $problem);
        }

        try {
            $issued = $this->workflow->update($program, $changes, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Updated the program', $e->getMessage());
        }

        return ToolResult::ok(
            "Updated {$program->name}",
            implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($changes)))
                .($issued > 0 ? "; {$issued} ".Str::plural('certificate', $issued).' issued to people who had completed it' : ''),
            $this->programCard($this->withCounts(TrainingProgram::query())->findOrFail($program->id), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function enroll(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        [$employees, $error] = $this->resolveEmployees(is_array($args['employees'] ?? null) ? $args['employees'] : [], self::MAX_ENROLL);

        if ($employees === null) {
            return ToolResult::error('Looked up the people', $error);
        }

        $outcome = $this->workflow->enroll($program, $employees->pluck('id'), ' via assistant', $user);
        [$message, $type] = $outcome->message();

        if ($outcome->enrolled() === 0) {
            return ToolResult::error("Enrolled people in {$program->name}", $message);
        }

        $names = $employees->whereIn('id', $outcome->enrolledIds)->map(fn (Employee $e): string => $e->full_name)->values();
        $left = $employees->whereNotIn('id', $outcome->enrolledIds)->map(fn (Employee $e): string => $e->full_name)->values();

        return ToolResult::ok(
            "Enrolled {$outcome->enrolled()} in {$program->name}",
            $message,
            $this->card(
                kind: 'add',
                tone: $type === 'success' ? 'positive' : 'warning',
                badge: 'Enrolled',
                title: $program->name,
                subtitle: $names->take(self::MAX_NAMES)->implode(', ').($names->count() > self::MAX_NAMES ? ' and '.($names->count() - self::MAX_NAMES).' more' : ''),
                meta: [
                    $message,
                    $left->isNotEmpty() ? 'Not enrolled: '.$left->take(self::MAX_NAMES)->implode(', ') : null,
                ],
                id: $program->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function grade(User $user, array $args): ToolResult
    {
        [$enrollment, $error] = $this->locateEnrollment($args);

        if ($enrollment === null) {
            return ToolResult::error('Looked up the enrollment', $error);
        }

        $changes = array_filter([
            'status' => in_array($args['status'] ?? null, TrainingEnrollment::ROSTER_STATUSES, true) ? $args['status'] : null,
            'score' => is_numeric($args['score'] ?? null) ? round((float) $args['score'], 2) : null,
            'remarks' => filled($args['remarks'] ?? null) ? trim((string) $args['remarks']) : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($changes === []) {
            return ToolResult::error('Updated the enrollment', 'Say what to record: a status, a score or remarks.');
        }

        $merged = [
            'status' => $enrollment->status,
            'score' => $enrollment->score,
            'remarks' => $enrollment->remarks,
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new TrainingEnrollmentRequest)->rules())) !== null) {
            return ToolResult::error('Updated the enrollment', $problem);
        }

        try {
            $this->workflow->grade($enrollment, $changes, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Updated the enrollment', $e->getMessage());
        }

        $enrollment->refresh()->load(['program:id,name,start_date,end_date', 'employee', 'certificate']);

        return ToolResult::ok(
            "Updated {$enrollment->employee?->full_name} in {$enrollment->program?->name}",
            $this->describeEnrollment($enrollment),
            $this->enrollmentCard(
                $enrollment,
                $enrollment->status === 'completed' ? 'approve' : 'edit',
                $enrollment->status === 'completed' ? 'positive' : 'info',
                ucfirst($enrollment->status),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeEnrollment(User $user, array $args): ToolResult
    {
        [$enrollment, $error] = $this->locateEnrollment($args);

        if ($enrollment === null) {
            return ToolResult::error('Looked up the enrollment', $error);
        }

        $card = $this->enrollmentCard($enrollment, 'cancel', 'warning', 'Removed');
        $name = $enrollment->employee?->full_name;
        $programName = $enrollment->program?->name;

        $this->workflow->remove($enrollment, ' via assistant');

        return ToolResult::ok("Removed {$name} from {$programName}", null, $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveProgram(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $card = $this->programCard($this->withCounts(TrainingProgram::query())->findOrFail($program->id), 'archive', 'warning', 'Archived');

        $this->workflow->archive($program, ' via assistant');

        return ToolResult::ok("Archived {$program->name}", 'It can be restored from the Training screen.', $card);
    }

    // ── Requests, sessions, certificates and money (ADRs 0074, 0075) ─────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findRequests(User $user, array $args): ToolResult
    {
        $program = null;

        if (filled($args['program'] ?? null)) {
            [$program, $error] = $this->locateProgram((string) $args['program']);

            if ($program === null) {
                return ToolResult::error('Looked up the program', $error);
            }
        }

        $requests = TrainingEnrollment::query()
            ->pending()
            ->whereHas('program')
            ->when($program !== null, fn (Builder $q) => $q->where('training_program_id', $program->id))
            ->with(['program:id,name,start_date,end_date,capacity', 'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo'])
            ->oldest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $requests->map(fn (TrainingEnrollment $e): array => $this->enrollmentCard($e, 'find', 'warning', 'Requested'))->all();

        return ToolResult::found('Searched requests to join', count($cards).' waiting', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function decideRequest(User $user, array $args): ToolResult
    {
        [$enrollment, $error] = $this->locateEnrollment($args);

        if ($enrollment === null) {
            return ToolResult::error('Looked up the request', $error);
        }

        $decision = $args['decision'] ?? null;

        if (! in_array($decision, ['approve', 'decline'], true)) {
            return ToolResult::error('Decided the request', 'Say whether to approve or decline it.');
        }

        try {
            $decision === 'approve'
                ? $this->selfService->approve($enrollment, $user, ' via assistant')
                : $this->selfService->decline($enrollment, $user, filled($args['note'] ?? null) ? (string) $args['note'] : null, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Decided the request', $e->getMessage());
        }

        $who = $enrollment->employee?->full_name;

        return ToolResult::ok(
            ($decision === 'approve' ? 'Approved' : 'Declined')." {$who}'s request to join {$enrollment->program?->name}",
            'They have been told.',
            $this->enrollmentCard($enrollment, $decision === 'approve' ? 'approve' : 'reject', $decision === 'approve' ? 'positive' : 'warning', $decision === 'approve' ? 'Enrolled' : 'Declined'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findSessions(User $user, array $args): ToolResult
    {
        $program = null;

        if (filled($args['program'] ?? null)) {
            [$program, $error] = $this->locateProgram((string) $args['program']);

            if ($program === null) {
                return ToolResult::error('Looked up the program', $error);
            }
        }

        $from = filled($args['from'] ?? null) ? $this->isoDate($args['from']) : null;
        $to = filled($args['to'] ?? null) ? $this->isoDate($args['to']) : null;

        if ((filled($args['from'] ?? null) && $from === null) || (filled($args['to'] ?? null) && $to === null)) {
            return ToolResult::error('Searched sessions', 'Give the dates as YYYY-MM-DD.');
        }

        // With no program and no dates, the next 30 days.
        if ($program === null && $from === null && $to === null) {
            $from = OrganizationClock::today();
            $to = now()->addDays(30)->toDateString();
        }

        $sessions = TrainingSession::query()
            ->ofLivePrograms()
            ->when($program !== null, fn (Builder $q) => $q->where('training_program_id', $program->id))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('session_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('session_date', '<=', $to))
            ->with(['program:id,name', 'attendances:id,training_session_id,status'])
            ->chronological()
            ->limit(self::MAX_RESULTS * 2)
            ->get();

        $cards = $sessions->map(fn (TrainingSession $session): array => $this->sessionCard($session, 'find', 'neutral'))->all();

        return ToolResult::found('Searched sessions', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function scheduleSession(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $data = [
            'title' => filled($args['title'] ?? null) ? (string) $args['title'] : null,
            'session_date' => $this->isoDate($args['date'] ?? null),
            'starts_at' => (string) ($args['starts_at'] ?? ''),
            'ends_at' => (string) ($args['ends_at'] ?? ''),
            'location' => filled($args['location'] ?? null) ? (string) $args['location'] : null,
            'repeat_until' => filled($args['repeat_until'] ?? null) ? $this->isoDate($args['repeat_until']) : null,
            'weekdays_only' => (bool) ($args['weekdays_only'] ?? false),
        ];

        if (($problem = $this->invalid($data, (new TrainingSessionRequest)->rules(), (new TrainingSessionRequest)->messages())) !== null) {
            return ToolResult::error('Scheduled the session', $problem);
        }

        try {
            $created = $this->sessions->create($program, $data, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Scheduled the session', $e->getMessage());
        }

        $first = $created->first();
        $card = $this->sessionCard($first->setRelation('program', $program), 'schedule', 'positive');

        if ($created->count() > 1) {
            $card['meta'][] = "{$created->count()} sessions, until ".$created->last()->session_date->format('M j, Y');
        }

        return ToolResult::ok(
            $created->count() === 1 ? "Scheduled a session of {$program->name}" : "Scheduled {$created->count()} sessions of {$program->name}",
            null,
            $card,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function recordAttendance(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $date = $this->isoDate($args['date'] ?? null);

        if ($date === null) {
            return ToolResult::error('Took attendance', 'Say which day, as YYYY-MM-DD.');
        }

        $sessions = $program->sessions()->whereDate('session_date', $date)->chronological()->get()
            ->when(filled($args['starts_at'] ?? null), fn ($c) => $c->filter(fn (TrainingSession $s): bool => $s->startTime() === substr((string) $args['starts_at'], 0, 5)));

        if ($sessions->count() !== 1) {
            return ToolResult::error('Took attendance', $sessions->isEmpty()
                ? "{$program->name} has no session on ".CarbonImmutable::parse($date)->format('M j, Y').'.'
                : 'That day has more than one session — say which by its start time ('.$sessions->map(fn (TrainingSession $s): string => $s->startTime())->implode(', ').').');
        }

        $session = $sessions->first();
        $seated = $program->enrollments()->active()->with('employee:id,first_name,middle_name,last_name,suffix,employee_no')->get();
        $marks = [];
        $named = 0;

        foreach (TrainingAttendance::STATUSES as $status) {
            $names = is_array($args[$status] ?? null) ? $args[$status] : [];

            if ($names === []) {
                continue;
            }

            [$employees, $error] = $this->resolveEmployees($names, self::MAX_MARKS);

            if ($employees === null) {
                return ToolResult::error('Looked up the people', $error);
            }

            foreach ($employees as $employee) {
                $enrollment = $seated->firstWhere('employee_id', $employee->id);

                if ($enrollment === null) {
                    return ToolResult::error('Took attendance', "{$employee->full_name} has no seat on {$program->name}.");
                }

                $marks[$enrollment->id] = $status;
                $named++;
            }
        }

        $rest = $args['everyone_else'] ?? null;

        if (in_array($rest, TrainingAttendance::STATUSES, true)) {
            foreach ($seated as $enrollment) {
                $marks[$enrollment->id] ??= $rest;
            }
        }

        if ($marks === []) {
            return ToolResult::error('Took attendance', 'Say who was present, late, absent or excused.');
        }

        try {
            $this->sessions->mark($session, $marks, $user, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Took attendance', $e->getMessage());
        }

        $session->load('attendances:id,training_session_id,status')->setRelation('program', $program);

        return ToolResult::ok(
            "Took attendance at {$program->name}",
            count($marks).' '.Str::plural('person', count($marks)).' marked',
            $this->sessionCard($session, 'approve', 'positive'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCertificates(User $user, array $args): ToolResult
    {
        $employee = null;

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        $standing = in_array($args['standing'] ?? null, TrainingCertificates::STANDINGS, true) ? $args['standing'] : null;

        $all = TrainingCertificates::register()
            ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->orderBy('expiry_date')
            ->get();
        $renewed = TrainingCertificates::renewedIds($all);

        $shown = $all
            ->filter(fn (EmployeeCertification $c): bool => $standing === null || TrainingCertificates::standing($c, isset($renewed[$c->id])) === $standing)
            ->take(self::MAX_RESULTS);

        $cards = $shown->map(fn (EmployeeCertification $c): array => $this->certificateCard($c, TrainingCertificates::standing($c, isset($renewed[$c->id]))))->values()->all();

        return ToolResult::found('Searched certificates', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function readBudget(User $user, array $args): ToolResult
    {
        if (filled($args['program'] ?? null)) {
            [$program, $error] = $this->locateProgram((string) $args['program']);

            if ($program === null) {
                return ToolResult::error('Looked up the program', $error);
            }

            $money = TrainingBudgets::forProgram($program);

            return ToolResult::found("Read {$program->name}'s budget", null, [
                $this->card(
                    kind: 'insight',
                    tone: $money['over_budget'] ? 'warning' : 'info',
                    badge: $money['over_budget'] ? 'Over budget' : 'Budget',
                    title: $program->name,
                    subtitle: 'Spent '.TrainingBudgets::money($money['spent']).($money['budget'] !== null ? ' of '.TrainingBudgets::money($money['budget']) : ', no budget set'),
                    meta: [
                        $money['remaining'] !== null ? ($money['remaining'] < 0 ? TrainingBudgets::money(abs($money['remaining'])).' over' : TrainingBudgets::money($money['remaining']).' left') : null,
                        $money['per_participant'] !== null ? 'Per participant '.TrainingBudgets::money($money['per_participant']) : null,
                        $money['per_completion'] !== null ? 'Per completion '.TrainingBudgets::money($money['per_completion']) : null,
                        $this->categoryLine($money['by_category']),
                    ],
                    id: $program->hashid,
                ),
            ]);
        }

        $year = is_numeric($args['year'] ?? null) ? (int) $args['year'] : (int) substr(OrganizationClock::today(), 0, 4);

        if ($year < 2000 || $year > 2100) {
            return ToolResult::error('Read the training budget', 'Give a year such as '.now()->year.'.');
        }

        $summary = TrainingBudgets::forYear($year);
        $top = collect($summary['programs'])->filter(fn (array $p): bool => $p['spent'] > 0)->take(5);

        return ToolResult::found("Read the {$year} training budget", null, [
            $this->card(
                kind: 'insight',
                tone: $summary['remaining'] !== null && $summary['remaining'] < 0 ? 'warning' : 'info',
                badge: 'Budget',
                title: "Training budget {$year}",
                subtitle: 'Spent '.TrainingBudgets::money($summary['spent']).($summary['budget'] !== null ? ' of '.TrainingBudgets::money($summary['budget'])." ({$summary['used_percent']}%)" : ', no budget set'),
                meta: [
                    $summary['remaining'] !== null ? ($summary['remaining'] < 0 ? TrainingBudgets::money(abs($summary['remaining'])).' over budget' : TrainingBudgets::money($summary['remaining']).' left') : null,
                    $summary['allocated'] > 0 ? 'Given to programs starting this year: '.TrainingBudgets::money($summary['allocated']) : null,
                    $this->categoryLine($summary['by_category']),
                    $top->isNotEmpty() ? 'Top spend: '.$top->map(fn (array $p): string => "{$p['name']} ".TrainingBudgets::money($p['spent']))->implode('; ') : null,
                ],
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function recordCost(User $user, array $args): ToolResult
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $data = [
            'category' => $args['category'] ?? null,
            'description' => filled($args['description'] ?? null) ? (string) $args['description'] : null,
            'amount' => $args['amount'] ?? null,
            'incurred_on' => filled($args['incurred_on'] ?? null) ? $this->isoDate($args['incurred_on']) : OrganizationClock::today(),
        ];

        if (($problem = $this->invalid($data, (new TrainingCostRequest)->rules())) !== null) {
            return ToolResult::error('Recorded the cost', $problem);
        }

        $cost = $this->budgets->record($program, $data, $user, ' via assistant');
        $money = TrainingBudgets::forProgram($program);

        return ToolResult::ok(
            'Recorded '.TrainingBudgets::money((float) $cost->amount)." on {$program->name}",
            null,
            $this->card(
                kind: 'add',
                tone: $money['over_budget'] ? 'warning' : 'positive',
                badge: str_replace('_', ' ', ucfirst($cost->category)),
                title: $program->name,
                subtitle: TrainingBudgets::money((float) $cost->amount).' on '.$cost->incurred_on->format('M j, Y'),
                meta: [
                    $cost->description,
                    'Spent '.TrainingBudgets::money($money['spent']).($money['budget'] !== null ? ' of '.TrainingBudgets::money($money['budget']) : ''),
                    $money['over_budget'] ? 'Over its budget' : null,
                ],
                id: $program->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setBudget(User $user, array $args): ToolResult
    {
        $data = ['year' => $args['year'] ?? null, 'amount' => $args['amount'] ?? null, 'notes' => $args['notes'] ?? null];

        if (($problem = $this->invalid($data, (new TrainingBudgetRequest)->rules())) !== null) {
            return ToolResult::error('Set the training budget', $problem);
        }

        $budget = $this->budgets->setBudget((int) $data['year'], (float) $data['amount'], filled($data['notes']) ? (string) $data['notes'] : null, ' via assistant');
        $summary = TrainingBudgets::forYear($budget->year);

        return ToolResult::ok(
            "Set the {$budget->year} training budget",
            null,
            $this->card(
                kind: 'edit',
                tone: 'positive',
                badge: 'Budget',
                title: "Training budget {$budget->year}",
                subtitle: TrainingBudgets::money((float) $budget->amount),
                meta: ['Spent so far '.TrainingBudgets::money($summary['spent'])],
            ),
        );
    }

    // ── The user's own training (ADR 0074) ───────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function myTraining(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Read your training', 'Your account is not linked to an employee record.');
        }

        $enrollments = TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereHas('program')
            ->with(['program:id,name,provider,start_date,end_date', 'attendances:id,training_enrollment_id,status'])
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $enrollments->map(function (TrainingEnrollment $e): array {
            $rate = TrainingSessions::rate($e->attendances->pluck('status'));
            $card = $this->enrollmentCard($e, 'find', $e->status === 'requested' ? 'warning' : 'neutral', ucfirst($e->status));
            $card['title'] = $e->program?->name ?? 'A program';
            $card['subtitle'] = $this->schedule($e->program);
            $card['avatar'] = null;
            $card['meta'] = array_values(array_filter([
                $this->describeEnrollment($e),
                $rate['rate'] !== null ? "Attendance {$rate['rate']}% ({$rate['attended']} of {$rate['counted']})" : null,
                $e->status === 'declined' && filled($e->decision_note) ? 'HR’s note: '.$e->decision_note : null,
            ]));

            return $card;
        })->all();

        $next = TrainingSession::query()
            ->whereIn('training_program_id', $enrollments->whereIn('status', TrainingEnrollment::SEATED_STATUSES)->pluck('training_program_id'))
            ->ofLivePrograms()
            ->whereDate('session_date', '>=', OrganizationClock::today())
            ->with('program:id,name')
            ->chronological()
            ->limit(5)
            ->get();

        foreach ($next as $session) {
            $cards[] = $this->sessionCard($session, 'schedule', 'info');
        }

        $certificates = TrainingCertificates::register()->where('employee_id', $employee->id)->latest('issued_date')->get();
        $renewed = TrainingCertificates::renewedIds($certificates);

        foreach ($certificates->take(self::MAX_RESULTS) as $certificate) {
            $cards[] = $this->certificateCard($certificate, TrainingCertificates::standing($certificate, isset($renewed[$certificate->id])));
        }

        return ToolResult::found(
            'Read your training',
            sprintf('%d %s, %d upcoming %s, %d %s', $enrollments->count(), Str::plural('program', $enrollments->count()), $next->count(), Str::plural('session', $next->count()), $certificates->count(), Str::plural('certificate', $certificates->count())),
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function openTrainings(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $employeeId = $user->employee()->value('id');

        $programs = $this->withCounts(TrainingProgram::query())
            ->where('enrollment_mode', '!=', 'closed')
            ->when($query !== '', fn (Builder $q) => $q->search($query))
            ->recentFirst()
            ->get()
            ->filter(fn (TrainingProgram $p): bool => $p->acceptsSelfEnrollment())
            ->take(self::MAX_RESULTS);

        $mine = $employeeId === null ? collect() : TrainingEnrollment::query()
            ->where('employee_id', $employeeId)
            ->whereIn('training_program_id', $programs->pluck('id'))
            ->pluck('status', 'training_program_id');

        $cards = $programs->map(function (TrainingProgram $p) use ($mine): array {
            $card = $this->programCard($p, 'find', 'neutral', $p->enrollment_mode === 'open' ? 'Open' : 'On approval');
            $card['meta'] = array_values(array_filter([
                $this->seats($p),
                $this->joining($p),
                $p->issues_certificate ? 'Earns a certificate' : null,
                $mine->has($p->id) ? 'You: '.$mine[$p->id] : null,
            ]));

            return $card;
        })->values()->all();

        return ToolResult::found('Searched the training you can join', count($cards).' open', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function joinTraining(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Joined the program', 'Your account is not linked to an employee record.');
        }

        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        try {
            $enrollment = $this->selfService->join($program, $employee, $user, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Joined the program', $e->getMessage());
        }

        $joined = $enrollment->status === 'enrolled';

        return ToolResult::ok(
            $joined ? "Joined {$program->name}" : "Asked to join {$program->name}",
            $joined ? 'Its sessions are on My training.' : 'HR will decide, and you will be told.',
            $this->programCard($this->withCounts(TrainingProgram::query())->findOrFail($program->id), 'add', $joined ? 'positive' : 'info', $joined ? 'Joined' : 'Requested'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function leaveTraining(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Left the program', 'Your account is not linked to an employee record.');
        }

        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the program', $error);
        }

        $enrollment = TrainingEnrollment::query()
            ->where('training_program_id', $program->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($enrollment === null) {
            return ToolResult::error('Left the program', "You are not on {$program->name}.");
        }

        $requested = $enrollment->status === 'requested';

        try {
            $this->selfService->withdraw($enrollment, ' via assistant');
        } catch (TrainingException $e) {
            return ToolResult::error('Left the program', $e->getMessage());
        }

        return ToolResult::ok($requested ? "Withdrew your request to join {$program->name}" : "Left {$program->name}");
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one (non-archived) program for a name, or why not: an exact name
     * first, then a partial match that only one program has.
     *
     * @return array{0: TrainingProgram|null, 1: string}
     */
    private function locateProgram(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [null, 'Say which training program.'];
        }

        $exact = TrainingProgram::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->limit(2)->get();

        if ($exact->count() === 1) {
            return [$exact->first(), ''];
        }

        $like = TrainingProgram::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $matches = $exact->isNotEmpty()
            ? $exact
            : TrainingProgram::query()->where('name', $like, '%'.$this->escapeLike($name).'%')->recentFirst()->limit(6)->get();

        return match (true) {
            $matches->isEmpty() => [null, 'No training program matches “'.Str::limit($name, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one program matches “'.Str::limit($name, 60).'”: '.$matches->take(5)->pluck('name')->implode(', ').'. Use the full name.'],
        };
    }

    /**
     * The enrollment the arguments mean: this person, on this program's roster.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: TrainingEnrollment|null, 1: string}
     */
    private function locateEnrollment(array $args): array
    {
        [$program, $error] = $this->locateProgram((string) ($args['program'] ?? ''));

        if ($program === null) {
            return [null, $error];
        }

        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return [null, $error];
        }

        $enrollment = TrainingEnrollment::query()
            ->with(['program:id,name,start_date,end_date', 'employee'])
            ->where('training_program_id', $program->id)
            ->where('employee_id', $employee->id)
            ->first();

        return $enrollment === null
            ? [null, "{$employee->full_name} is not enrolled in {$program->name}."]
            : [$enrollment, ''];
    }

    /**
     * The program fields present in the arguments, typed for the model — or why
     * one of them is not usable.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $fields  Already-chosen values (the name).
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function programFields(array $args, array $fields): array
    {
        foreach (['provider', 'description'] as $key) {
            if (filled($args[$key] ?? null)) {
                $fields[$key] = trim((string) $args[$key]);
            }
        }

        foreach (['start_date', 'end_date'] as $key) {
            if (! filled($args[$key] ?? null)) {
                continue;
            }

            $date = $this->isoDate($args[$key]);

            if ($date === null) {
                return [[], 'Give the '.str_replace('_', ' ', $key).' as YYYY-MM-DD.'];
            }

            $fields[$key] = $date;
        }

        if (array_key_exists('capacity', $args) && is_numeric($args['capacity'])) {
            $capacity = (int) $args['capacity'];
            $fields['capacity'] = $capacity === 0 ? null : $capacity;
        }

        if (in_array($args['enrollment_mode'] ?? null, TrainingProgram::ENROLLMENT_MODES, true)) {
            $fields['enrollment_mode'] = $args['enrollment_mode'];
        }

        if (array_key_exists('issues_certificate', $args) && is_bool($args['issues_certificate'])) {
            $fields['issues_certificate'] = $args['issues_certificate'];
        }

        if (array_key_exists('certificate_validity_months', $args) && is_numeric($args['certificate_validity_months'])) {
            $months = (int) $args['certificate_validity_months'];
            $fields['certificate_validity_months'] = $months === 0 ? null : $months;
            // A validity says the program earns certificates.
            $fields['issues_certificate'] ??= true;
        }

        if (array_key_exists('budget', $args) && is_numeric($args['budget'])) {
            $budget = round((float) $args['budget'], 2);
            $fields['budget'] = $budget === 0.0 ? null : $budget;
        }

        if (array_key_exists('name', $fields)) {
            $fields['name'] = trim((string) $fields['name']);
        }

        return [$fields, null];
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * The training picture in lines — the overview screen, read aloud.
     *
     * @return list<string>
     */
    private function pictureLines(): array
    {
        $programs = $this->withCounts(TrainingProgram::query())->recentFirst()->get();

        if ($programs->isEmpty()) {
            return [];
        }

        $byStatus = $programs->groupBy(fn (TrainingProgram $p): string => $p->status());
        $ongoing = $byStatus->get('ongoing', collect());
        $upcoming = $byStatus->get('upcoming', collect())->sortBy(fn (TrainingProgram $p): string => $p->start_date?->toDateString() ?? '9999-12-31');

        $completedYear = TrainingEnrollment::query()
            ->whereHas('program')
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subYear())
            ->get(['score']);

        // Ended programs with people still "enrolled" — the at-risk read the
        // program screen shows, across every program.
        $followUp = TrainingProgram::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', today())
            ->withCount(['enrollments as stalled_count' => fn (Builder $q) => $q->where('status', 'enrolled')])
            ->get()
            ->filter(fn (TrainingProgram $p): bool => $p->stalled_count > 0)
            ->sortByDesc('stalled_count')
            ->take(3);

        return array_values(array_filter([
            sprintf(
                'Programs: %d ongoing, %d upcoming, %d completed; %d people currently enrolled.',
                $ongoing->count(),
                $byStatus->get('upcoming', collect())->count(),
                $byStatus->get('completed', collect())->count(),
                (int) $programs->sum(fn (TrainingProgram $p): int => (int) $p->active_enrollments_count - (int) $p->completed_enrollments_count),
            ),
            sprintf(
                'Completions in the last 12 months: %d%s.',
                $completedYear->count(),
                $completedYear->whereNotNull('score')->isNotEmpty()
                    ? ', average score '.$this->number($completedYear->whereNotNull('score')->avg(fn (TrainingEnrollment $e): float => (float) $e->score))
                    : '',
            ),
            $ongoing->isNotEmpty()
                ? 'Running now: '.$ongoing->take(4)->map(fn (TrainingProgram $p): string => "{$p->name} ({$this->seats($p)}, ends ".($p->end_date?->format('M j') ?? 'open-ended').')')->implode('; ').'.'
                : null,
            $upcoming->isNotEmpty()
                ? 'Coming up: '.$upcoming->take(4)->map(fn (TrainingProgram $p): string => "{$p->name} (starts ".($p->start_date?->format('M j, Y') ?? 'date not set').", {$this->seats($p)})")->implode('; ').'.'
                : null,
            $followUp->isNotEmpty()
                ? 'Needs follow-up (ended, people still enrolled): '.$followUp->map(fn (TrainingProgram $p): string => "{$p->name} {$p->stalled_count}")->implode('; ').'.'
                : null,
            ($waiting = (int) $programs->sum('pending_enrollments_count')) > 0
                ? "Requests to join waiting for a decision: {$waiting}."
                : null,
            $this->certificateLine(),
            $this->budgetLine(),
        ]));
    }

    /**
     * @param  Builder<TrainingProgram>  $query
     * @return Builder<TrainingProgram>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount(['enrollments' => fn (Builder $q) => $q->onRoster()])
            ->withCount(['enrollments as pending_enrollments_count' => fn (Builder $q) => $q->pending()])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->withCount(['enrollments as completed_enrollments_count' => fn (Builder $q) => $q->where('status', 'completed')]);
    }

    /**
     * @param  EloquentCollection<int, TrainingEnrollment>  $enrollments
     */
    private function namesWith(EloquentCollection $enrollments, string $status): string
    {
        $names = $enrollments->where('status', $status)->map(fn (TrainingEnrollment $e): string => $e->employee?->full_name ?? 'Unknown')->values();

        return $names->take(self::MAX_NAMES)->implode(', ').($names->count() > self::MAX_NAMES ? ' and '.($names->count() - self::MAX_NAMES).' more' : '');
    }

    /**
     * @param  array{total: int, completed: int, dropped: int, enrolled: int, completion_rate: int|null}  $stats
     */
    private function outcomeLine(array $stats): string
    {
        if ($stats['total'] === 0) {
            return 'Nobody enrolled yet';
        }

        return sprintf(
            '%d enrolled in total: %d completed, %d in progress, %d dropped (completion %s%%)',
            $stats['total'],
            $stats['completed'],
            $stats['enrolled'],
            $stats['dropped'],
            $stats['completion_rate'] ?? 0,
        );
    }

    private function joining(TrainingProgram $program): string
    {
        return match ($program->enrollment_mode) {
            'open' => 'Anyone can join while seats last',
            'approval' => 'Anyone can ask to join; HR decides',
            default => 'HR enrolls people',
        };
    }

    private function seats(TrainingProgram $program): string
    {
        $taken = (int) ($program->active_enrollments_count ?? $program->enrollments()->active()->count());

        return $program->capacity === null
            ? "{$taken} enrolled, uncapped"
            : "{$taken} of {$program->capacity} seats taken".($taken >= $program->capacity ? ' (full)' : '');
    }

    private function schedule(TrainingProgram $program): string
    {
        $start = $program->start_date?->format('M j, Y');
        $end = $program->end_date?->format('M j, Y');

        return match (true) {
            $start !== null && $end !== null => "{$start} – {$end}",
            $start !== null => "from {$start}",
            $end !== null => "until {$end}",
            default => 'no dates set',
        };
    }

    private function describeEnrollment(TrainingEnrollment $e, bool $withProgram = false): string
    {
        $what = match ($e->status) {
            'completed' => 'completed'.($e->completed_at ? ' on '.$e->completed_at->format('M j, Y') : ''),
            'dropped' => 'dropped',
            'requested' => 'asked to join, waiting for HR',
            'declined' => 'request to join declined',
            default => 'enrolled'.($e->program?->status() === 'completed' ? ' (the program has ended)' : ''),
        };

        return trim(
            ($withProgram ? ($e->program?->name ?? 'A program').': ' : '')
            .$what
            .($e->score !== null ? ', score '.$this->number($e->score) : '')
            .(filled($e->remarks) ? ' — '.Str::limit((string) $e->remarks, 140) : ''),
        ).'.';
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * @return array<string, mixed>
     */
    private function programCard(TrainingProgram $program, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $program->name,
            subtitle: trim(($program->provider ? $program->provider.' · ' : '').$this->schedule($program)),
            meta: [$this->seats($program), $program->status()],
            id: $program->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentCard(TrainingEnrollment $e, string $kind, string $tone, string $badge): array
    {
        $employee = $e->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: $e->program?->name,
            meta: [$this->describeEnrollment($e)],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $e->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionCard(TrainingSession $session, string $kind, string $tone): array
    {
        $marks = $session->relationLoaded('attendances') ? $session->attendances->pluck('status') : collect();
        $rate = TrainingSessions::rate($marks);

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $session->isHeld() ? 'Held' : 'Upcoming',
            title: trim(($session->program?->name ?? 'Training').($session->title ? ' — '.$session->title : '')),
            subtitle: $session->session_date->format('D, M j, Y').', '.$session->startTime().'–'.$session->endTime(),
            meta: [
                $session->location,
                ! $session->isHeld() ? null : ($marks->isEmpty()
                    ? 'Attendance not taken yet'
                    : "{$rate['attended']} attended, ".$marks->filter(fn (string $s): bool => $s === 'absent')->count().' absent'),
            ],
            id: $session->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateCard(EmployeeCertification $certificate, string $standing): array
    {
        $employee = $certificate->employee;

        return $this->card(
            kind: 'find',
            tone: match ($standing) {
                'expired' => 'danger',
                'expiring' => 'warning',
                'renewed' => 'neutral',
                default => 'positive',
            },
            badge: match ($standing) {
                'expiring' => 'Expiring soon',
                default => ucfirst($standing),
            },
            title: $certificate->name,
            subtitle: trim(($employee?->full_name ?? '').' · '.$certificate->certificate_no, ' ·'),
            meta: [
                'Issued '.$certificate->issued_date?->format('M j, Y'),
                $certificate->expiry_date ? ($standing === 'expired' ? 'Expired ' : 'Expires ').$certificate->expiry_date->format('M j, Y') : 'Never expires',
            ],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $certificate->id,
        );
    }

    /**
     * Training certificates that need attention, for the picture.
     */
    private function certificateLine(): ?string
    {
        $all = TrainingCertificates::register()->whereNotNull('expiry_date')->get();

        if ($all->isEmpty()) {
            return null;
        }

        $renewed = TrainingCertificates::renewedIds(TrainingCertificates::register()->get());
        $standings = $all->map(fn (EmployeeCertification $c): string => TrainingCertificates::standing($c, isset($renewed[$c->id])))->countBy();

        $expiring = (int) $standings->get('expiring', 0);
        $expired = (int) $standings->get('expired', 0);

        if ($expiring === 0 && $expired === 0) {
            return null;
        }

        return 'Certificates: '.$expiring.' expiring within '.TrainingCertificates::EXPIRING_DAYS.' days, '.$expired.' expired and not renewed.';
    }

    /**
     * This year's training money, for the picture.
     */
    private function budgetLine(): ?string
    {
        $year = (int) substr(OrganizationClock::today(), 0, 4);
        $summary = TrainingBudgets::forYear($year);

        if ($summary['budget'] === null && $summary['spent'] === 0.0) {
            return null;
        }

        return "Training spend {$year}: ".TrainingBudgets::money($summary['spent'])
            .($summary['budget'] !== null ? ' of a '.TrainingBudgets::money($summary['budget'])." budget ({$summary['used_percent']}%)." : ' (no budget set).');
    }

    /**
     * @param  array<string, float>  $byCategory
     */
    private function categoryLine(array $byCategory): ?string
    {
        if ($byCategory === []) {
            return null;
        }

        return 'By category: '.collect($byCategory)
            ->map(fn (float $amount, string $category): string => str_replace('_', ' ', $category).' '.TrainingBudgets::money($amount))
            ->implode('; ');
    }
}
