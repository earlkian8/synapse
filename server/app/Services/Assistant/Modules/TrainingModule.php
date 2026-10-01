<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Http\Requests\Training\TrainingProgramRequest;
use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;
use App\Support\Training\TrainingWorkflow;
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
 * Disclosure follows the screens: everything needs `training.view` (there is no
 * self-service training view), and every change `training.manage`. Removing
 * somebody from a roster — their score and remarks go with them — and archiving
 * a program wait for the user's Confirm (ADR 0049).
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

    public function __construct(private readonly TrainingWorkflow $workflow) {}

    public function key(): string
    {
        return 'training';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('training.view');
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
        ];
    }

    protected function confirmTools(): array
    {
        // A removal takes the person's score and remarks with it; archiving
        // takes a whole program off the screens.
        return ['remove_from_training', 'archive_training_program'];
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
            return $this->denied($permission === 'training.manage' ? 'change training' : 'view training');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $programs = $this->catalog(
            TrainingProgram::query()->recentFirst()->limit(20)->get(['name', 'start_date', 'end_date'])
                ->reject(fn (TrainingProgram $p): bool => $p->status() === 'completed')
                ->map(fn (TrainingProgram $p): string => "{$p->name} ({$p->status()})"),
        );

        $manage = $this->allows($user, 'training.manage')
            ? <<<'TXT'

            - create_training_program / update_training_program set a program's name, provider, description, dates (YYYY-MM-DD) and seat capacity (0 removes the cap on an update).
            - enroll_in_training enrolls up to 25 people, by name; inactive and already-enrolled people are skipped and capacity is respected.
            - update_training_enrollment sets one person's status, score (0–100) or remarks — mark them completed when they finished.
            - remove_from_training takes someone off a roster (their score and remarks go with them); archive_training_program archives a program. These wait for the user's confirmation.
            TXT
            : '';

        return <<<TXT
        TRAINING — the company's training programs and who is enrolled in each. A program's status (upcoming → ongoing → completed) follows its dates; an enrollment is enrolled → completed or dropped, with an optional 0–100 score.
        - find_training_programs lists programs; get_training_program reads one — schedule, seats, completion rate, average score, and who is at risk or dropped; find_training_enrollments lists a person's (or a program's) enrollments; training_summary reads the whole training picture.
        - Pass programs by name, and people by name or employee number.{$manage}
          Current programs: {$programs}
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
        ];

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
                'description' => "Change a program's name, provider, description, dates or capacity. Only the fields given change.",
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
                        'status' => ['type' => 'STRING', 'enum' => TrainingEnrollment::STATUSES],
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

        $card = $this->programCard($program, 'insight', $stats['at_risk'] > 0 ? 'warning' : 'info', ucfirst($program->status()));
        $card['meta'] = array_values(array_filter([
            $this->seats($program),
            $this->outcomeLine($stats),
            $stats['average_score'] !== null ? 'Average score '.$this->number($stats['average_score']) : null,
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
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new TrainingProgramRequest)->rules())) !== null) {
            return ToolResult::error('Updated the program', $problem);
        }

        $this->workflow->update($program, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$program->name}",
            implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($changes))),
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

        $outcome = $this->workflow->enroll($program, $employees->pluck('id'), ' via assistant');
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
            'status' => in_array($args['status'] ?? null, TrainingEnrollment::STATUSES, true) ? $args['status'] : null,
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

        $this->workflow->grade($enrollment, $changes, ' via assistant');
        $enrollment->refresh()->load(['program:id,name,start_date,end_date', 'employee']);

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
        ]));
    }

    /**
     * @param  Builder<TrainingProgram>  $query
     * @return Builder<TrainingProgram>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount('enrollments')
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
}
