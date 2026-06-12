<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Recruitment\StoreApplicantRequest;
use App\Http\Requests\Recruitment\StoreInterviewRequest;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Position;
use App\Models\User;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\ApplicantHirer;
use App\Support\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Recruitment capability: manage job postings and the applicant pipeline —
 * create postings, add candidates, move/reject applications, schedule interviews
 * and hire. Mutations mirror the recruitment controllers' validation, logging
 * and notifications, and hiring reuses {@see ApplicantHirer}.
 */
class RecruitmentModule extends Module
{
    /** Non-terminal stages an application may be moved to. */
    private const MOVABLE_STAGES = ['applied', 'screening', 'interview', 'offer'];

    public function key(): string
    {
        return 'recruitment';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('recruitment.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_job_postings' => 'findPostings',
            'create_job_posting' => 'createPosting',
            'find_applications' => 'findApplications',
            'add_application' => 'addApplication',
            'move_application' => 'moveApplication',
            'reject_application' => 'rejectApplication',
            'schedule_interview' => 'scheduleInterview',
            'hire_applicant' => 'hireApplicant',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(): string
    {
        $departments = Department::orderBy('name')->get(['name'])->pluck('name')->implode(', ') ?: 'none';
        $positions = Position::orderBy('title')->get(['title'])->pluck('title')->implode(', ') ?: 'none';
        $postingTypes = implode(', ', StoreJobPostingRequest::EMPLOYMENT_TYPES);
        $sources = implode(', ', StoreApplicantRequest::SOURCES);

        return <<<TXT
        RECRUITMENT — job postings and the applicant pipeline (applied → screening → interview → offer → hired/rejected).
        - create_job_posting opens a vacancy. add_application adds a candidate to a posting's pipeline (creating the applicant if new).
        - move_application, reject_application, schedule_interview and hire_applicant act on a candidate's most recent ACTIVE application — pass the candidate by name.
        - hire_applicant creates an Employee from the application and seeds onboarding (irreversible) — only do this on a clear request.
        - Pass `posting` as a title, `applicant` as a name, and department/position as labels.
          Departments: {$departments}. Positions: {$positions}.
          posting employment_type: {$postingTypes}. applicant source: {$sources}.
        TXT;
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'find_job_postings',
                'description' => 'List job postings, optionally filtered by text and/or status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING'],
                        'status' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'create_job_posting',
                'description' => 'Create a job posting (vacancy).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'title' => ['type' => 'STRING'],
                        'department' => ['type' => 'STRING', 'description' => 'Department name.'],
                        'position' => ['type' => 'STRING', 'description' => 'Position title.'],
                        'employment_type' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::EMPLOYMENT_TYPES],
                        'openings' => ['type' => 'INTEGER', 'description' => 'Defaults to 1.'],
                        'status' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::STATUSES, 'description' => 'Defaults to open.'],
                        'description' => ['type' => 'STRING'],
                        'requirements' => ['type' => 'STRING'],
                        'closing_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'find_applications',
                'description' => 'List pipeline applications, optionally filtered by candidate name.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['query' => ['type' => 'STRING', 'description' => 'Candidate name.']],
                ],
            ],
            [
                'name' => 'add_application',
                'description' => "Add a candidate to a posting's pipeline (creates the applicant if new).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'posting' => ['type' => 'STRING', 'description' => 'Job posting title.'],
                        'first_name' => ['type' => 'STRING'],
                        'last_name' => ['type' => 'STRING'],
                        'email' => ['type' => 'STRING'],
                        'phone' => ['type' => 'STRING'],
                        'headline' => ['type' => 'STRING'],
                        'source' => ['type' => 'STRING', 'enum' => StoreApplicantRequest::SOURCES],
                        'expected_salary' => ['type' => 'NUMBER'],
                        'cover_note' => ['type' => 'STRING'],
                        'rating' => ['type' => 'INTEGER', 'description' => '1–5'],
                    ],
                    'required' => ['posting', 'first_name', 'last_name'],
                ],
            ],
            [
                'name' => 'move_application',
                'description' => "Move a candidate's active application to another pipeline stage.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => ['type' => 'STRING', 'description' => 'Candidate name.'],
                        'stage' => ['type' => 'STRING', 'enum' => self::MOVABLE_STAGES],
                    ],
                    'required' => ['applicant', 'stage'],
                ],
            ],
            [
                'name' => 'reject_application',
                'description' => "Reject a candidate's active application.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => ['type' => 'STRING', 'description' => 'Candidate name.'],
                        'reason' => ['type' => 'STRING'],
                    ],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'schedule_interview',
                'description' => "Schedule an interview for a candidate's active application.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => ['type' => 'STRING', 'description' => 'Candidate name.'],
                        'scheduled_at' => ['type' => 'STRING', 'description' => 'Date/time, e.g. 2026-06-20 14:00.'],
                        'mode' => ['type' => 'STRING', 'enum' => StoreInterviewRequest::MODES],
                        'interviewer' => ['type' => 'STRING', 'description' => 'Interviewer name (a system user).'],
                        'location' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                    ],
                    'required' => ['applicant', 'scheduled_at', 'mode'],
                ],
            ],
            [
                'name' => 'hire_applicant',
                'description' => 'Hire a candidate — creates an employee from their application and seeds onboarding. Irreversible.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => ['type' => 'STRING', 'description' => 'Candidate name.']],
                    'required' => ['applicant'],
                ],
            ],
        ];
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPostings(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $status = $this->normaliseStatus($args['status'] ?? null);

        $postings = JobPosting::query()
            ->with(['department', 'position'])
            ->withCount(['applications as open_count' => fn ($q) => $q->whereNotIn('stage', ['hired', 'rejected'])])
            ->search($query)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(8)
            ->get();

        $cards = $postings->map(fn (JobPosting $p): array => $this->postingCard($p, 'find', 'neutral', ucfirst($p->status)))->all();

        return ToolResult::found($query !== '' ? "Searched postings for “{$query}”" : 'Listed job postings', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createPosting(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.create')) {
            return $this->denied('create job postings');
        }

        $data = [
            'title' => trim((string) ($args['title'] ?? '')),
            'department_id' => $this->resolveId(Department::query(), 'name', $this->firstFilled($args, ['department', 'department_name'])),
            'position_id' => $this->resolveId(Position::query(), 'title', $this->firstFilled($args, ['position', 'position_title'])),
            'description' => $args['description'] ?? null,
            'requirements' => $args['requirements'] ?? null,
            'employment_type' => $args['employment_type'] ?? 'probationary',
            'openings' => (int) ($args['openings'] ?? 1) ?: 1,
            'status' => $this->normaliseStatus($args['status'] ?? null) ?? 'open',
            'closing_date' => $this->date($args['closing_date'] ?? null),
        ];

        $validator = Validator::make($data, (new StoreJobPostingRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the job posting', $validator->errors()->first());
        }

        $posting = JobPosting::create([...$validator->validated(), 'posted_by' => $user->id]);

        ActivityLogger::log(
            event: 'created',
            description: "Created job posting \"{$posting->title}\" via assistant",
            subject: $posting,
            logName: 'recruitment',
            subjectLabel: $posting->title,
        );

        return ToolResult::ok("Created posting “{$posting->title}”", null, $this->postingCard($posting->load(['department', 'position']), 'post', 'positive', 'Posted'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findApplications(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'applicant', 'match']));

        $applications = JobApplication::query()
            ->with(['applicant', 'jobPosting'])
            ->when($query !== '', fn ($q) => $q->whereHas('applicant', fn ($a) => $this->matchByTokens($a, $query)))
            ->latest('applied_at')
            ->limit(8)
            ->get();

        $cards = $applications->map(fn (JobApplication $a): array => $this->applicationCard($a, 'find', 'neutral', ucfirst($a->stage)))->all();

        return ToolResult::found($query !== '' ? "Searched applications for “{$query}”" : 'Listed applications', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.create')) {
            return $this->denied('add candidates');
        }

        $posting = $this->locatePosting($args);
        if (! $posting) {
            return ToolResult::error('Looked up the posting', 'No matching job posting found.');
        }

        $applicantData = [
            'first_name' => trim((string) ($args['first_name'] ?? '')),
            'last_name' => trim((string) ($args['last_name'] ?? '')),
            'email' => $args['email'] ?? null,
            'phone' => $args['phone'] ?? null,
            'headline' => $args['headline'] ?? null,
            'source' => in_array($args['source'] ?? null, StoreApplicantRequest::SOURCES, true) ? $args['source'] : 'other',
        ];

        $validator = Validator::make($applicantData, collect((new StoreApplicantRequest)->rules())->only(['first_name', 'last_name', 'email', 'phone', 'headline', 'source'])->all());

        if ($validator->fails()) {
            return ToolResult::error('Validated the candidate', $validator->errors()->first());
        }

        // Reuse an existing applicant with the same name, else create one.
        $applicant = Applicant::query()
            ->whereRaw('lower(first_name) = ?', [mb_strtolower($applicantData['first_name'])])
            ->whereRaw('lower(last_name) = ?', [mb_strtolower($applicantData['last_name'])])
            ->first() ?? Applicant::create($applicantData);

        if ($posting->applications()->where('applicant_id', $applicant->id)->exists()) {
            return ToolResult::error("Checked {$applicant->full_name}", 'That candidate is already in this pipeline.');
        }

        $application = $posting->applications()->create([
            'applicant_id' => $applicant->id,
            'stage' => 'applied',
            'expected_salary' => $args['expected_salary'] ?? null,
            'cover_note' => $args['cover_note'] ?? null,
            'rating' => isset($args['rating']) ? (int) $args['rating'] : null,
            'applied_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "{$applicant->full_name} applied for \"{$posting->title}\" via assistant",
            subject: $posting,
            logName: 'recruitment',
            subjectLabel: $posting->title,
        );

        Notifier::toRole(
            'hr-manager',
            'New application',
            "{$applicant->full_name} applied for {$posting->title}.",
            url: '/recruitment/'.$posting->getRouteKey(),
            category: 'recruitment',
            actor: $user,
        );

        $application->setRelation('applicant', $applicant)->setRelation('jobPosting', $posting);

        return ToolResult::ok("Added {$applicant->full_name} to “{$posting->title}”", null, $this->applicationCard($application, 'add', 'positive', 'Applied'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function moveApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.manage-pipeline')) {
            return $this->denied('move applications');
        }

        $stage = strtolower(trim((string) ($args['stage'] ?? '')));
        if (! in_array($stage, self::MOVABLE_STAGES, true)) {
            return ToolResult::error('Moved the application', 'Stage must be one of: '.implode(', ', self::MOVABLE_STAGES).'.');
        }

        $application = $this->locateApplication($args);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No active application found for that candidate.');
        }

        $application->update(['stage' => $stage, 'rejected_reason' => null, 'decided_at' => null]);

        ActivityLogger::log(
            event: 'updated',
            description: "Moved {$application->applicant->full_name} to {$stage} via assistant",
            subject: $application->jobPosting,
            properties: ['stage' => $stage],
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        return ToolResult::ok("Moved {$application->applicant->full_name} to {$stage}", null, $this->applicationCard($application->fresh(['applicant', 'jobPosting']), 'move', 'info', ucfirst($stage)));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function rejectApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.manage-pipeline')) {
            return $this->denied('reject applications');
        }

        $application = $this->locateApplication($args);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No active application found for that candidate.');
        }

        $application->update(['stage' => 'rejected', 'rejected_reason' => $args['reason'] ?? null, 'decided_at' => now()]);

        ActivityLogger::log(
            event: 'updated',
            description: "Rejected {$application->applicant->full_name} via assistant",
            subject: $application->jobPosting,
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        return ToolResult::ok("Rejected {$application->applicant->full_name}", null, $this->applicationCard($application->fresh(['applicant', 'jobPosting']), 'reject', 'danger', 'Rejected'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function scheduleInterview(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.schedule-interviews')) {
            return $this->denied('schedule interviews');
        }

        $application = $this->locateApplication($args);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No active application found for that candidate.');
        }

        $interviewerId = null;
        $interviewerName = $this->firstFilled($args, ['interviewer', 'interviewer_name']);
        if ($interviewerName !== null) {
            $interviewerId = $this->matchByTokens(User::query()->where('is_active', true), $interviewerName)->value('id');
        }

        $data = [
            'interviewer_id' => $interviewerId,
            'scheduled_at' => $this->dateTime($args['scheduled_at'] ?? null),
            'mode' => $args['mode'] ?? null,
            'location' => $args['location'] ?? null,
            'notes' => $args['notes'] ?? null,
        ];

        $validator = Validator::make($data, (new StoreInterviewRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the interview', $validator->errors()->first());
        }

        $application->interviews()->create($validator->validated());

        if (in_array($application->stage, ['applied', 'screening'], true)) {
            $application->update(['stage' => 'interview']);
        }

        ActivityLogger::log(
            event: 'created',
            description: "Scheduled an interview for {$application->applicant->full_name} via assistant",
            subject: $application->jobPosting,
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        $when = Carbon::parse($data['scheduled_at'])->format('M j, g:i A');

        return ToolResult::ok(
            "Scheduled interview for {$application->applicant->full_name}",
            $when,
            $this->applicationCard($application->fresh(['applicant', 'jobPosting']), 'schedule', 'info', 'Interview'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function hireApplicant(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.hire')) {
            return $this->denied('hire applicants');
        }

        $application = $this->locateApplication($args);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No active application found for that candidate.');
        }

        try {
            $employee = ApplicantHirer::hire($application, $user);
        } catch (RuntimeException $e) {
            return ToolResult::error("Checked {$application->applicant->full_name}", $e->getMessage());
        }

        $employee->load(['department', 'position']);
        $subtitle = collect([$employee->position?->title, $employee->department?->name])->filter()->implode(' · ');

        return ToolResult::ok(
            "Hired {$application->applicant->full_name}",
            $employee->employee_no,
            $this->card(
                kind: 'hire',
                tone: 'positive',
                badge: 'Hired',
                title: $employee->full_name,
                subtitle: $subtitle ?: 'New hire',
                meta: [$employee->employee_no],
                avatar: ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
                id: $employee->id,
            ),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function locatePosting(array $args): ?JobPosting
    {
        $needle = $this->firstFilled($args, ['posting', 'job_posting', 'title']);

        return $needle ? JobPosting::query()->search($needle)->latest()->first() : null;
    }

    /**
     * The candidate's most recent active (non-terminal) application.
     *
     * @param  array<string, mixed>  $args
     */
    private function locateApplication(array $args): ?JobApplication
    {
        $needle = $this->firstFilled($args, ['applicant', 'candidate', 'match', 'name']);

        if ($needle === null) {
            return null;
        }

        $applicant = $this->matchByTokens(Applicant::query(), $needle)->first();

        if (! $applicant) {
            return null;
        }

        return JobApplication::query()
            ->with(['applicant', 'jobPosting'])
            ->where('applicant_id', $applicant->id)
            ->whereNotIn('stage', ['hired', 'rejected'])
            ->latest('applied_at')
            ->first();
    }

    private function normaliseStatus(mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, StoreJobPostingRequest::STATUSES, true) ? $status : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        try {
            return $value === '' ? null : Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        try {
            return $value === '' ? null : Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function postingCard(JobPosting $posting, string $kind, string $tone, string $badge): array
    {
        $subtitle = collect([$posting->position?->title, $posting->department?->name])->filter()->implode(' · ');
        $open = $posting->open_count ?? null;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $posting->title,
            subtitle: $subtitle ?: ucfirst(str_replace('_', ' ', (string) $posting->employment_type)),
            meta: [ucfirst($posting->status), $posting->openings.' opening'.($posting->openings === 1 ? '' : 's'), $open !== null ? $open.' active' : null],
            id: $posting->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationCard(JobApplication $application, string $kind, string $tone, string $badge): array
    {
        $applicant = $application->applicant;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $applicant?->full_name ?? 'Candidate',
            subtitle: $application->jobPosting?->title,
            meta: [ucfirst($application->stage)],
            avatar: $applicant
                ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                : null,
            id: $application->id,
        );
    }
}
