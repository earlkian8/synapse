<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Recruitment\StoreApplicantRequest;
use App\Http\Requests\Recruitment\StoreInterviewRequest;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Http\Requests\Recruitment\UpdateJobPostingRequest;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Position;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Models\User;
use App\Queries\RecruitmentStatistics;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\ApplicantDocumentStore;
use App\Support\ApplicantHirer;
use App\Support\Notifier;
use App\Support\Recruitment\ApplicantInsights;
use App\Support\Recruitment\ApplicantScorer;
use App\Support\Recruitment\InterviewScheduler;
use App\Support\Recruitment\PipelineInsights;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Recruitment capability — the whole applicant tracking system, agentically.
 *
 * The tool surface mirrors what a recruiter can do on the board: run the
 * vacancies (create / edit / re-status / delete), curate the candidate pool,
 * drive the pipeline (add, move, advance, rate, reject, withdraw, hire), manage
 * the interview calendar, and read the decision support — the deterministic fit
 * ranking as well as the LLM's grounded read of one candidate.
 *
 * Every mutation mirrors the recruitment controllers' validation, activity
 * logging and notifications, and reuses the canonical support classes rather
 * than re-deriving them: {@see ApplicantScorer} for fit and the recommended next
 * step, {@see PipelineInsights} for the board-level read, {@see ApplicantHirer}
 * for the hire bridge, {@see InterviewScheduler} for booking, and
 * {@see ApplicantDocumentStore} for file cleanup. Tools are advertised only to
 * users whose permissions allow them, and each handler re-checks anyway.
 */
class RecruitmentModule extends Module
{
    /** How many records a find_* tool returns at most. */
    private const FIND_LIMIT = 8;

    /** How many candidates a ranking returns by default / at most. */
    private const RANK_DEFAULT = 5;

    private const RANK_MAX = 10;

    public function __construct(
        private readonly ApplicantScorer $scorer,
        private readonly ApplicantInsights $insights,
        private readonly PipelineInsights $pipeline,
        private readonly RecruitmentStatistics $statistics,
    ) {}

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
            // Vacancies.
            'find_job_postings' => 'findPostings',
            'create_job_posting' => 'createPosting',
            'update_job_posting' => 'updatePosting',
            'set_posting_status' => 'setPostingStatus',
            'delete_job_posting' => 'deletePosting',

            // Candidate pool.
            'find_applicants' => 'findApplicants',
            'add_applicant' => 'addApplicant',
            'update_applicant' => 'updateApplicant',
            'delete_applicant' => 'deleteApplicant',

            // Pipeline.
            'find_applications' => 'findApplications',
            'add_application' => 'addApplication',
            'move_application' => 'moveApplication',
            'advance_application' => 'advanceApplication',
            'update_application' => 'updateApplication',
            'reject_application' => 'rejectApplication',
            'withdraw_application' => 'withdrawApplication',
            'hire_applicant' => 'hireApplicant',

            // Interviews.
            'find_interviews' => 'findInterviews',
            'schedule_interview' => 'scheduleInterview',
            'update_interview' => 'updateInterview',
            'cancel_interview' => 'cancelInterview',

            // Decision support.
            'recruitment_summary' => 'recruitmentSummary',
            'rank_candidates' => 'rankCandidates',
            'candidate_profile' => 'candidateProfile',
            'candidate_insights' => 'candidateInsights',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'create_job_posting' => 'recruitment.create',
            'update_job_posting' => 'recruitment.update',
            'set_posting_status' => 'recruitment.update',
            'delete_job_posting' => 'recruitment.delete',

            'add_applicant' => 'recruitment.create',
            'update_applicant' => 'recruitment.update',
            'delete_applicant' => 'recruitment.delete',

            'add_application' => 'recruitment.create',
            'move_application' => 'recruitment.manage-pipeline',
            'advance_application' => 'recruitment.manage-pipeline',
            'update_application' => 'recruitment.update',
            'reject_application' => 'recruitment.manage-pipeline',
            'withdraw_application' => 'recruitment.manage-pipeline',
            'hire_applicant' => 'recruitment.hire',

            'schedule_interview' => 'recruitment.schedule-interviews',
            'update_interview' => 'recruitment.schedule-interviews',
            'cancel_interview' => 'recruitment.schedule-interviews',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $departments = Department::orderBy('name')->pluck('name')->implode(', ') ?: 'none';
        $positions = Position::orderBy('title')->pluck('title')->implode(', ') ?: 'none';
        $postingTypes = implode(', ', StoreJobPostingRequest::EMPLOYMENT_TYPES);
        $sources = implode(', ', StoreApplicantRequest::SOURCES);
        $defaultPipeline = $this->defaultPipeline();
        $stages = $defaultPipeline ? $defaultPipeline->stages->pluck('name')->implode(' → ') : null;
        $movable = $defaultPipeline ? $defaultPipeline->stages->where('kind', 'open')->pluck('name')->implode(', ') : null;

        $lines = [
            $stages
                ? "RECRUITMENT — the applicant tracking system: job postings and the hiring pipeline. The organisation's default pipeline is {$stages} — but every posting can use a different, custom pipeline, so ALWAYS use the exact stage names a tool returned for that posting (in find_job_postings, find_applications, a candidate's profile) rather than assuming these names apply everywhere."
                : 'RECRUITMENT — the applicant tracking system: job postings and the hiring pipeline. This organisation has not configured a hiring pipeline yet (Company Setup → Recruitment Pipelines) — creating a posting will fail until one exists; tell the user to set one up first.',
            '- Reading: find_job_postings, find_applicants (the candidate pool), find_applications (the pipeline), find_interviews.',
            '- Decision support: recruitment_summary (org-wide, or one posting\'s pipeline health), rank_candidates (best-fit shortlist for a posting), candidate_profile (one candidate\'s fit breakdown, rating, interviews and recommended next step).',
            '- candidate_insights asks the LLM to READ the candidate\'s actual résumé and documents. It costs a model call, so use it only when the user explicitly wants an AI read/opinion of a candidate; the saved read is returned unless they ask to refresh it.',
        ];

        if ($this->allows($user, 'recruitment.create')) {
            $lines[] = '- create_job_posting opens a vacancy on the organisation\'s default pipeline (an `open` posting needs a closing date). add_applicant puts someone in the pool without a vacancy; add_application puts a candidate into a posting\'s pipeline, at its first stage (creating the applicant if new).';
        }

        if ($this->allows($user, 'recruitment.update')) {
            $lines[] = '- update_job_posting edits a vacancy (including its screening criteria); set_posting_status moves it draft → open → closed / filled. update_applicant edits a candidate\'s profile; update_application sets the recruiter rating (1–5), salary expectation or cover note.';
        }

        if ($this->allows($user, 'recruitment.manage-pipeline')) {
            $lines[] = '- move_application moves a candidate to a named stage of their own posting\'s pipeline'.($movable ? " (on the default pipeline: {$movable})" : '').'; advance_application takes whatever the system recommends as their next step. reject_application turns them down; withdraw_application removes the card from the pipeline entirely.';
        }

        if ($this->allows($user, 'recruitment.schedule-interviews')) {
            $lines[] = '- schedule_interview books an interview (and pulls the candidate into the interview stage); update_interview reschedules one or records its outcome (passed/failed) and feedback; cancel_interview removes it.';
        }

        if ($this->allows($user, 'recruitment.hire')) {
            $lines[] = '- hire_applicant creates an Employee from the application, provisions their login and seeds onboarding. It is IRREVERSIBLE — only on a clear, explicit request.';
        }

        $lines[] = '- Pass `posting` as a job title, `applicant` as a candidate name, and department/position as labels. Never invent a candidate: if no one matches, say so.';
        $lines[] = "  Departments: {$departments}. Positions: {$positions}.";
        $lines[] = "  posting employment_type: {$postingTypes}. applicant source: {$sources}.";

        return implode("\n", $lines);
    }

    public function tools(User $user): array
    {
        $applicantArg = ['type' => 'STRING', 'description' => 'Candidate name.'];
        $postingArg = ['type' => 'STRING', 'description' => 'Job posting title.'];

        return $this->permitted($user, [
            // ── Vacancies ────────────────────────────────────────────────────
            [
                'name' => 'find_job_postings',
                'description' => 'List job postings, optionally filtered by text, status, department, or how soon they close.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING'],
                        'status' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::STATUSES],
                        'department' => ['type' => 'STRING', 'description' => 'Department name.'],
                        'closing_within_days' => ['type' => 'INTEGER', 'description' => 'Only postings whose deadline falls within this many days (0 = today or already past due).'],
                    ],
                ],
            ],
            [
                'name' => 'create_job_posting',
                'description' => 'Create a job posting (vacancy). An open posting needs a closing date.',
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
                        'min_years_experience' => ['type' => 'INTEGER', 'description' => 'Screening criterion: minimum years of experience.'],
                        'skills' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Screening criterion: required skill keywords.'],
                        'closing_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; required when status is open.'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'update_job_posting',
                'description' => 'Edit an existing job posting — any field, including its screening criteria and closing date. Only pass what changes.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'posting' => $postingArg,
                        'title' => ['type' => 'STRING', 'description' => 'A new title.'],
                        'department' => ['type' => 'STRING'],
                        'position' => ['type' => 'STRING'],
                        'employment_type' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::EMPLOYMENT_TYPES],
                        'openings' => ['type' => 'INTEGER'],
                        'description' => ['type' => 'STRING'],
                        'requirements' => ['type' => 'STRING'],
                        'min_years_experience' => ['type' => 'INTEGER'],
                        'skills' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'closing_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['posting'],
                ],
            ],
            [
                'name' => 'set_posting_status',
                'description' => 'Move a posting through its lifecycle: draft, open (publish), closed, or filled.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'posting' => $postingArg,
                        'status' => ['type' => 'STRING', 'enum' => StoreJobPostingRequest::STATUSES],
                        'closing_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; needed when publishing a posting that has none.'],
                    ],
                    'required' => ['posting', 'status'],
                ],
            ],
            [
                'name' => 'delete_job_posting',
                'description' => 'Permanently delete a job posting and its pipeline. Only on an explicit request.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['posting' => $postingArg],
                    'required' => ['posting'],
                ],
            ],

            // ── Candidate pool ───────────────────────────────────────────────
            [
                'name' => 'find_applicants',
                'description' => 'Search the candidate pool (people, not applications) by name, email or headline.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Name, email or keyword.'],
                        'source' => ['type' => 'STRING', 'enum' => StoreApplicantRequest::SOURCES],
                    ],
                ],
            ],
            [
                'name' => 'add_applicant',
                'description' => 'Add someone to the candidate pool without attaching them to a vacancy. Fields may be read from an attached résumé.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $this->applicantProperties(),
                    'required' => ['first_name', 'last_name'],
                ],
            ],
            [
                'name' => 'update_applicant',
                'description' => "Update a candidate's profile details. Only pass what changes.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg] + $this->applicantProperties(),
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'delete_applicant',
                'description' => 'Remove a candidate from the pool, with their applications and files. Only on an explicit request.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg],
                    'required' => ['applicant'],
                ],
            ],

            // ── Pipeline ─────────────────────────────────────────────────────
            [
                'name' => 'find_applications',
                'description' => 'List pipeline applications, optionally filtered by candidate, posting, stage, or whether they have gone quiet.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Candidate name.'],
                        'posting' => $postingArg,
                        'stage' => ['type' => 'STRING', 'description' => 'Exact stage name — use what a posting\'s own pipeline calls it (stages vary by posting).'],
                        'stalled' => ['type' => 'BOOLEAN', 'description' => 'Only candidates sitting untouched past the stall threshold.'],
                    ],
                ],
            ],
            [
                'name' => 'add_application',
                'description' => "Add a candidate to a posting's pipeline (creates the applicant if new).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['posting' => $postingArg] + $this->applicantProperties() + [
                        'expected_salary' => ['type' => 'NUMBER'],
                        'cover_note' => ['type' => 'STRING'],
                        'rating' => ['type' => 'INTEGER', 'description' => '1–5'],
                    ],
                    'required' => ['posting', 'first_name', 'last_name'],
                ],
            ],
            [
                'name' => 'move_application',
                'description' => "Move a candidate's application to another pipeline stage. Also reinstates a rejected candidate.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'stage' => ['type' => 'STRING', 'description' => 'Exact name of an open (non-terminal) stage on the candidate\'s posting\'s pipeline.'],
                        'posting' => ['type' => 'STRING', 'description' => 'Narrow to one posting when the candidate applied to several.'],
                    ],
                    'required' => ['applicant', 'stage'],
                ],
            ],
            [
                'name' => 'advance_application',
                'description' => "Take the system's recommended next step for a candidate (e.g. advance to screening or move to offer). Will not reject or hire on its own.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg, 'posting' => $postingArg],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'update_application',
                'description' => "Set the recruiter rating, salary expectation or cover note on a candidate's application.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'posting' => $postingArg,
                        'rating' => ['type' => 'INTEGER', 'description' => 'Recruiter rating, 1–5.'],
                        'expected_salary' => ['type' => 'NUMBER'],
                        'cover_note' => ['type' => 'STRING'],
                    ],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'reject_application',
                'description' => 'Turn a candidate down, recording the reason on their application.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'posting' => $postingArg,
                        'reason' => ['type' => 'STRING'],
                    ],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'withdraw_application',
                'description' => "Remove a candidate's application from the pipeline entirely (e.g. they withdrew). The candidate stays in the pool.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg, 'posting' => $postingArg],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'hire_applicant',
                'description' => 'Hire a candidate — creates an employee from their application, invites them to the app and seeds onboarding. Irreversible.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'posting' => $postingArg,
                        'send_invitation' => ['type' => 'BOOLEAN', 'description' => 'Email the new hire an invitation to join the app. Defaults to true.'],
                    ],
                    'required' => ['applicant'],
                ],
            ],

            // ── Interviews ───────────────────────────────────────────────────
            [
                'name' => 'find_interviews',
                'description' => 'List interviews — upcoming by default — optionally filtered by candidate, interviewer, posting or outcome.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'interviewer' => ['type' => 'STRING', 'description' => 'Interviewer name (a system user).'],
                        'posting' => $postingArg,
                        'when' => ['type' => 'STRING', 'enum' => ['upcoming', 'past', 'today', 'all'], 'description' => 'Defaults to upcoming.'],
                        'result' => ['type' => 'STRING', 'enum' => InterviewScheduler::RESULTS],
                    ],
                ],
            ],
            [
                'name' => 'schedule_interview',
                'description' => "Schedule an interview for a candidate's active application (advances them into the interview stage).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'posting' => $postingArg,
                        'scheduled_at' => ['type' => 'STRING', 'description' => 'Date/time, e.g. 2026-06-20 14:00.'],
                        'mode' => ['type' => 'STRING', 'enum' => StoreInterviewRequest::MODES],
                        'interviewer' => ['type' => 'STRING', 'description' => 'Interviewer name (a system user).'],
                        'location' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                        'stage' => ['type' => 'STRING', 'description' => 'Which pipeline stage to move the candidate to once scheduled. Defaults to the pipeline\'s next open stage.'],
                    ],
                    'required' => ['applicant', 'scheduled_at', 'mode'],
                ],
            ],
            [
                'name' => 'update_interview',
                'description' => "Reschedule a candidate's interview, or record its outcome and feedback. Targets their next upcoming interview, else their latest one.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'scheduled_at' => ['type' => 'STRING', 'description' => 'New date/time.'],
                        'mode' => ['type' => 'STRING', 'enum' => StoreInterviewRequest::MODES],
                        'interviewer' => ['type' => 'STRING'],
                        'location' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                        'result' => ['type' => 'STRING', 'enum' => InterviewScheduler::RESULTS],
                        'feedback' => ['type' => 'STRING'],
                    ],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'cancel_interview',
                'description' => "Cancel a candidate's next scheduled interview.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg],
                    'required' => ['applicant'],
                ],
            ],

            // ── Decision support ─────────────────────────────────────────────
            [
                'name' => 'recruitment_summary',
                'description' => "How hiring is going: the org-wide picture, or one posting's pipeline health (average fit, strong candidates, ready to advance, stalled, standout).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['posting' => ['type' => 'STRING', 'description' => 'Job posting title; omit for the whole organisation.']],
                ],
            ],
            [
                'name' => 'rank_candidates',
                'description' => "Shortlist a posting's best-fit candidates, ranked by fit score, with the recommended next step for each.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'posting' => $postingArg,
                        'stage' => ['type' => 'STRING', 'description' => 'Only candidates in this exact stage name (this posting\'s pipeline).'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'How many to return (default 5, max 10).'],
                    ],
                    'required' => ['posting'],
                ],
            ],
            [
                'name' => 'candidate_profile',
                'description' => "One candidate's decision-support snapshot: fit score and why, rank in the pipeline, rating, interview history and the recommended next step. No model call.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['applicant' => $applicantArg, 'posting' => $postingArg],
                    'required' => ['applicant'],
                ],
            ],
            [
                'name' => 'candidate_insights',
                'description' => 'An AI read of one candidate grounded in their actual résumé and documents. Costs a model call — only when the user asks for an AI opinion/assessment.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'applicant' => $applicantArg,
                        'posting' => $postingArg,
                        'refresh' => ['type' => 'BOOLEAN', 'description' => 'Re-read the documents instead of returning the saved read.'],
                    ],
                    'required' => ['applicant'],
                ],
            ],
        ]);
    }

    // ── Vacancies ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPostings(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $status = $this->normaliseStatus($args['status'] ?? null);
        $department = $this->firstFilled($args, ['department', 'department_name']);
        $withinDays = isset($args['closing_within_days']) ? max(0, (int) $args['closing_within_days']) : null;

        $postings = JobPosting::query()
            ->with(['department', 'position'])
            ->withCount(['applications as open_count' => fn (Builder $q) => $q->open()])
            ->search($query)
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($department, fn (Builder $q) => $q->whereHas('department', fn (Builder $d) => $d->whereRaw('lower(name) = ?', [mb_strtolower($department)])))
            ->when($withinDays !== null, fn (Builder $q) => $q
                ->whereNotNull('closing_date')
                ->whereDate('closing_date', '<=', now()->addDays($withinDays)->toDateString()))
            ->latest()
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $postings->map(fn (JobPosting $p): array => $this->postingCard($p, 'find', 'neutral', ucfirst($p->status)))->all();

        return ToolResult::found(
            $query !== '' ? "Searched postings for “{$query}”" : 'Listed job postings',
            count($cards).' found',
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createPosting(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.create')) {
            return $this->denied('create job postings');
        }

        $pipeline = $this->defaultPipeline();
        if ($pipeline === null) {
            return ToolResult::error(
                'Checked the hiring pipeline',
                'This organisation has not set up a hiring pipeline yet — configure one in Company Setup → Recruitment Pipelines first.',
            );
        }

        $department = $this->resolveLabel(Department::query(), 'name', $this->firstFilled($args, ['department', 'department_name']));
        if ($department instanceof ToolResult) {
            return $department;
        }

        $position = $this->resolveLabel(Position::query(), 'title', $this->firstFilled($args, ['position', 'position_title']));
        if ($position instanceof ToolResult) {
            return $position;
        }

        $data = [
            'title' => trim((string) ($args['title'] ?? '')),
            'recruitment_pipeline_id' => $pipeline->id,
            'department_id' => $department,
            'position_id' => $position,
            'description' => $args['description'] ?? null,
            'requirements' => $args['requirements'] ?? null,
            'min_years_experience' => isset($args['min_years_experience']) ? (int) $args['min_years_experience'] : null,
            'skills' => $this->skills($args['skills'] ?? null),
            'employment_type' => $args['employment_type'] ?? 'probationary',
            'openings' => (int) ($args['openings'] ?? 1) ?: 1,
            'status' => $this->normaliseStatus($args['status'] ?? null) ?? 'open',
            'closing_date' => $this->date($args['closing_date'] ?? null),
        ];

        $validator = Validator::make($data, (new StoreJobPostingRequest)->rules(), (new StoreJobPostingRequest)->messages());

        if ($validator->fails()) {
            return ToolResult::error('Validated the job posting', $validator->errors()->first());
        }

        $posting = JobPosting::create([...$validator->validated(), 'posted_by' => $user->id]);

        $this->log('created', "Created job posting \"{$posting->title}\" via assistant", $posting, $posting->title);

        return ToolResult::ok(
            "Created posting “{$posting->title}”",
            null,
            $this->postingCard($posting->load(['department', 'position']), 'post', 'positive', 'Posted'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updatePosting(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.update')) {
            return $this->denied('edit job postings');
        }

        $posting = $this->locatePosting($args);
        if (! $posting) {
            return ToolResult::error('Looked up the posting', 'No matching job posting found.');
        }

        $data = [
            'title' => $this->firstFilled($args, ['title', 'new_title']) ?? $posting->title,
            'recruitment_pipeline_id' => $posting->recruitment_pipeline_id,
            'department_id' => $posting->department_id,
            'position_id' => $posting->position_id,
            'description' => array_key_exists('description', $args) ? $args['description'] : $posting->description,
            'requirements' => array_key_exists('requirements', $args) ? $args['requirements'] : $posting->requirements,
            'min_years_experience' => array_key_exists('min_years_experience', $args)
                ? (filled($args['min_years_experience']) ? (int) $args['min_years_experience'] : null)
                : $posting->min_years_experience,
            'skills' => array_key_exists('skills', $args) ? $this->skills($args['skills']) : $posting->skills,
            'employment_type' => $args['employment_type'] ?? $posting->employment_type,
            'openings' => isset($args['openings']) ? (int) $args['openings'] : $posting->openings,
            'status' => $this->normaliseStatus($args['status'] ?? null) ?? $posting->status,
            'closing_date' => filled($args['closing_date'] ?? null)
                ? $this->date($args['closing_date'])
                : $posting->closing_date?->toDateString(),
        ];

        if (filled($args['department'] ?? null)) {
            $department = $this->resolveLabel(Department::query(), 'name', (string) $args['department']);
            if ($department instanceof ToolResult) {
                return $department;
            }
            $data['department_id'] = $department;
        }

        if (filled($args['position'] ?? null)) {
            $position = $this->resolveLabel(Position::query(), 'title', (string) $args['position']);
            if ($position instanceof ToolResult) {
                return $position;
            }
            $data['position_id'] = $position;
        }

        $validator = Validator::make($data, (new UpdateJobPostingRequest)->rules(), (new UpdateJobPostingRequest)->messages());

        if ($validator->fails()) {
            return ToolResult::error('Validated the job posting', $validator->errors()->first());
        }

        $posting->update($validator->validated());

        $this->log('updated', "Updated job posting \"{$posting->title}\" via assistant", $posting, $posting->title);

        return ToolResult::ok(
            "Updated posting “{$posting->title}”",
            null,
            $this->postingCard($posting->fresh(['department', 'position']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setPostingStatus(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.update')) {
            return $this->denied('change a posting’s status');
        }

        $posting = $this->locatePosting($args);
        if (! $posting) {
            return ToolResult::error('Looked up the posting', 'No matching job posting found.');
        }

        $status = $this->normaliseStatus($args['status'] ?? null);
        if ($status === null) {
            return ToolResult::error('Checked the status', 'Status must be one of: '.implode(', ', StoreJobPostingRequest::STATUSES).'.');
        }

        $changes = ['status' => $status];

        // Publishing keeps the domain rule the form enforces: an open posting must
        // tell candidates when applications close.
        if ($status === 'open') {
            $closing = $this->date($args['closing_date'] ?? null) ?? $posting->closing_date?->toDateString();

            if ($closing === null) {
                return ToolResult::error('Checked the posting', 'An open posting needs a closing date so applicants know the deadline.');
            }

            $changes['closing_date'] = $closing;
        }

        $posting->update($changes);

        $this->log('updated', "Set posting \"{$posting->title}\" to {$status} via assistant", $posting, $posting->title, ['status' => $status]);

        return ToolResult::ok(
            "Set “{$posting->title}” to {$status}",
            null,
            $this->postingCard($posting->fresh(['department', 'position']), $status === 'open' ? 'post' : 'edit', $status === 'closed' ? 'warning' : 'info', ucfirst($status)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deletePosting(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.delete')) {
            return $this->denied('delete job postings');
        }

        $posting = $this->locatePosting($args);
        if (! $posting) {
            return ToolResult::error('Looked up the posting', 'No matching job posting found.');
        }

        $title = $posting->title;
        $card = $this->postingCard($posting->load(['department', 'position']), 'archive', 'danger', 'Deleted');

        $posting->delete();

        $this->log('deleted', "Deleted job posting \"{$title}\" via assistant", null, $title);

        return ToolResult::ok("Deleted posting “{$title}”", null, $card);
    }

    // ── Candidate pool ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findApplicants(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'applicant', 'name', 'match']));
        $source = in_array($args['source'] ?? null, StoreApplicantRequest::SOURCES, true) ? $args['source'] : null;

        $applicants = Applicant::query()
            ->withCount('applications')
            ->when($query !== '', fn (Builder $q) => $this->matchByTokens($q, $query))
            ->when($source, fn (Builder $q) => $q->where('source', $source))
            ->latest()
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $applicants->map(fn (Applicant $a): array => $this->applicantCard($a, 'find', 'neutral', 'Candidate'))->all();

        return ToolResult::found(
            $query !== '' ? "Searched the candidate pool for “{$query}”" : 'Listed candidates',
            count($cards).' found',
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addApplicant(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.create')) {
            return $this->denied('add candidates');
        }

        $data = $this->applicantData($args);

        $validator = Validator::make($data, $this->applicantRules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the candidate', $validator->errors()->first());
        }

        if ($existing = $this->existingApplicant($data)) {
            return ToolResult::error("Checked {$existing->full_name}", 'That candidate is already in the pool — update them instead.');
        }

        $applicant = Applicant::create($validator->validated());

        $this->log('created', "Added applicant {$applicant->full_name} to the pool via assistant", $applicant, $applicant->full_name);

        return ToolResult::ok("Added {$applicant->full_name} to the pool", null, $this->applicantCard($applicant, 'add', 'positive', 'Added'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateApplicant(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.update')) {
            return $this->denied('edit candidates');
        }

        $applicant = $this->locateApplicant($args);
        if (! $applicant) {
            return ToolResult::error('Looked up the candidate', 'No matching candidate found.');
        }

        $changes = array_filter(
            $this->applicantData($args, defaults: false),
            fn ($value): bool => $value !== null,
        );

        // The name keys double as the lookup, so an unchanged name is not an edit.
        foreach (['first_name', 'last_name'] as $key) {
            if (($changes[$key] ?? null) === $applicant->{$key}) {
                unset($changes[$key]);
            }
        }

        if ($changes === []) {
            return ToolResult::error("Checked {$applicant->full_name}", 'Nothing to update — tell me which details to change.');
        }

        $validator = Validator::make($changes, collect($this->applicantRules())->only(array_keys($changes))->all());

        if ($validator->fails()) {
            return ToolResult::error('Validated the candidate', $validator->errors()->first());
        }

        $applicant->update($validator->validated());

        $this->log('updated', "Updated applicant {$applicant->full_name} via assistant", $applicant, $applicant->full_name);

        return ToolResult::ok(
            "Updated {$applicant->full_name}",
            implode(', ', array_keys($changes)),
            $this->applicantCard($applicant->fresh(), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteApplicant(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.delete')) {
            return $this->denied('delete candidates');
        }

        $applicant = $this->locateApplicant($args);
        if (! $applicant) {
            return ToolResult::error('Looked up the candidate', 'No matching candidate found.');
        }

        if ($applicant->applications()->whereHas('pipelineStage', fn (Builder $q) => $q->where('kind', 'won'))->exists()) {
            return ToolResult::error("Checked {$applicant->full_name}", 'That candidate has already been hired — their record is part of the employee’s history.');
        }

        $label = $applicant->full_name;
        $card = $this->applicantCard($applicant, 'archive', 'danger', 'Removed');

        ApplicantDocumentStore::purge($applicant);
        $applicant->delete();

        $this->log('deleted', "Removed applicant {$label} via assistant", null, $label);

        return ToolResult::ok("Removed {$label} from the pool", null, $card);
    }

    // ── Pipeline ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findApplications(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'applicant', 'match']));
        $posting = $this->locatePosting($args);
        $stalled = (bool) ($args['stalled'] ?? false);
        $stageName = $this->firstFilled($args, ['stage']);
        $stageIds = null;

        if ($stageName !== null && $posting !== null) {
            $stage = $this->resolveStageByName($posting->pipeline, $stageName);

            if ($stage === null) {
                return ToolResult::error(
                    'Checked the stage',
                    'No stage named “'.$stageName.'” on that posting\'s pipeline. Valid stages: '.$posting->pipeline->stages->pluck('name')->implode(', ').'.',
                );
            }

            $stageIds = [$stage->id];
        } elseif ($stageName !== null) {
            // No posting named — match the stage by name across every pipeline
            // in the tenant, since more than one may share a stage name.
            $stageIds = RecruitmentPipelineStage::whereRaw('lower(name) = ?', [mb_strtolower($stageName)])->pluck('id');

            if ($stageIds->isEmpty()) {
                return ToolResult::error('Checked the stage', 'No stage named “'.$stageName.'” found.');
            }
        }

        $applications = JobApplication::query()
            ->with(['applicant', 'jobPosting', 'pipelineStage'])
            ->when($query !== '', fn (Builder $q) => $q->whereHas('applicant', fn (Builder $a) => $this->matchByTokens($a, $query)))
            ->when($posting, fn (Builder $q) => $q->where('job_posting_id', $posting->id))
            ->when($stageIds, fn (Builder $q) => $q->whereIn('recruitment_pipeline_stage_id', $stageIds))
            ->when($stalled, fn (Builder $q) => $q->stalled())
            ->latest('applied_at')
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $applications->map(fn (JobApplication $a): array => $this->applicationCard($a, 'find', 'neutral', ucfirst($a->pipelineStage->name)))->all();

        $label = match (true) {
            $stalled => 'Looked for stalled candidates',
            $query !== '' => "Searched applications for “{$query}”",
            $posting !== null => "Listed the “{$posting->title}” pipeline",
            default => 'Listed applications',
        };

        return ToolResult::found($label, count($cards).' found', $cards);
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

        $applicantData = $this->applicantData($args);

        $validator = Validator::make($applicantData, $this->applicantRules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the candidate', $validator->errors()->first());
        }

        // Reuse the pool entry when we already know this person, else create one.
        $applicant = $this->existingApplicant($applicantData) ?? Applicant::create($validator->validated());

        if ($posting->applications()->where('applicant_id', $applicant->id)->exists()) {
            return ToolResult::error("Checked {$applicant->full_name}", 'That candidate is already in this pipeline.');
        }

        $details = Validator::make([
            'expected_salary' => $args['expected_salary'] ?? null,
            'cover_note' => $args['cover_note'] ?? null,
            'rating' => isset($args['rating']) ? (int) $args['rating'] : null,
        ], collect((new StoreJobApplicationRequest)->rules())->only(['expected_salary', 'cover_note', 'rating'])->all());

        if ($details->fails()) {
            return ToolResult::error('Validated the application', $details->errors()->first());
        }

        $entryStage = $posting->pipeline->entryStage();

        $application = $posting->applications()->create([
            ...$details->validated(),
            'applicant_id' => $applicant->id,
            'recruitment_pipeline_stage_id' => $entryStage->id,
            'applied_at' => now(),
        ]);

        $this->log('created', "{$applicant->full_name} applied for \"{$posting->title}\" via assistant", $posting, $posting->title);

        Notifier::toRole(
            'hr-manager',
            'New application',
            "{$applicant->full_name} applied for {$posting->title}.",
            url: '/recruitment/'.$posting->getRouteKey(),
            category: 'recruitment',
            actor: $user,
        );

        $application->setRelation('applicant', $applicant)->setRelation('jobPosting', $posting);

        $application->setRelation('pipelineStage', $entryStage);

        return ToolResult::ok("Added {$applicant->full_name} to “{$posting->title}”", null, $this->applicationCard($application, 'add', 'positive', $entryStage->name));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function moveApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.manage-pipeline')) {
            return $this->denied('move applications');
        }

        // Fall back to a decided application so a rejected candidate can be
        // brought back into the pipeline; a hire is final and stays that way.
        $application = $this->locateApplication($args) ?? $this->locateApplication($args, activeOnly: false);

        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No application found for that candidate.');
        }

        if ($application->pipelineStage->kind === 'won') {
            return ToolResult::error("Checked {$application->applicant->full_name}", 'That candidate has already been hired.');
        }

        $pipeline = $application->jobPosting->pipeline;
        $stage = $this->resolveStageByName($pipeline, $args['stage'] ?? null, kind: 'open');

        if ($stage === null) {
            $openNames = $pipeline->stages->where('kind', 'open')->pluck('name')->implode(', ');

            return ToolResult::error('Moved the application', "Stage must be one of: {$openNames}.");
        }

        $reinstated = $application->pipelineStage->kind === 'lost';
        $application->moveTo($stage);

        $this->log(
            'updated',
            ($reinstated ? "Reinstated {$application->applicant->full_name} at " : "Moved {$application->applicant->full_name} to ")."{$stage->name} via assistant",
            $application->jobPosting,
            $application->applicant->full_name,
            ['stage' => $stage->name],
        );

        return ToolResult::ok(
            ($reinstated ? "Reinstated {$application->applicant->full_name} at " : "Moved {$application->applicant->full_name} to ").$stage->name,
            null,
            $this->applicationCard($application->fresh(['applicant', 'jobPosting', 'pipelineStage']), 'move', 'info', ucfirst($stage->name)),
        );
    }

    /**
     * Take whatever the {@see ApplicantScorer} recommends as this candidate's next
     * step. Negative or irreversible recommendations (reject, hire) are reported
     * back rather than performed — those stay explicit decisions.
     *
     * @param  array<string, mixed>  $args
     */
    private function advanceApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.manage-pipeline')) {
            return $this->denied('move applications');
        }

        $application = $this->locateApplication($args);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No active application found for that candidate.');
        }

        $name = $application->applicant->full_name;
        $score = $this->score($application);
        $recommendation = $this->scorer->recommendation($application, $score);
        $action = $recommendation['action'];

        if ($action === null) {
            return ToolResult::error("Reviewed {$name}", $recommendation['label'].' — '.$recommendation['hint']);
        }

        if ($action !== 'advance') {
            return ToolResult::error(
                "Reviewed {$name}",
                "The recommended next step is “{$recommendation['label']}” — tell me to do that explicitly and I will.",
            );
        }

        $stage = RecruitmentPipelineStage::findOrFail($recommendation['stage_id']);
        $application->moveTo($stage);

        $this->log('updated', "Advanced {$name} to {$stage->name} via assistant", $application->jobPosting, $name, ['stage' => $stage->name]);

        return ToolResult::ok(
            "Advanced {$name} to {$stage->name}",
            $recommendation['hint'],
            $this->applicationCard($application->fresh(['applicant', 'jobPosting', 'pipelineStage']), 'move', 'positive', ucfirst($stage->name)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.update')) {
            return $this->denied('edit applications');
        }

        $application = $this->locateApplication($args, activeOnly: false);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No application found for that candidate.');
        }

        $changes = array_filter([
            'rating' => isset($args['rating']) ? (int) $args['rating'] : null,
            'expected_salary' => $args['expected_salary'] ?? null,
            'cover_note' => $args['cover_note'] ?? null,
        ], fn ($value): bool => $value !== null);

        if ($changes === []) {
            return ToolResult::error('Updated the application', 'Tell me what to set — a rating (1–5), a salary expectation, or a note.');
        }

        $validator = Validator::make($changes, collect((new StoreJobApplicationRequest)->rules())->only(array_keys($changes))->all());

        if ($validator->fails()) {
            return ToolResult::error('Validated the application', $validator->errors()->first());
        }

        $application->update($validator->validated());

        $name = $application->applicant->full_name;
        $detail = isset($changes['rating']) ? "Rated {$changes['rating']}/5" : implode(', ', array_keys($changes));

        $this->log('updated', "Updated {$name}'s application via assistant", $application->jobPosting, $name, $changes);

        return ToolResult::ok(
            "Updated {$name}'s application",
            $detail,
            $this->applicationCard($application->fresh(['applicant', 'jobPosting', 'pipelineStage']), 'edit', 'info', 'Updated'),
        );
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

        $application->rejectWith(reason: $this->firstFilled($args, ['reason', 'rejected_reason']));

        $name = $application->applicant->full_name;
        $this->log('updated', "Rejected {$name} via assistant", $application->jobPosting, $name);

        return ToolResult::ok(
            "Rejected {$name}",
            null,
            $this->applicationCard($application->fresh(['applicant', 'jobPosting', 'pipelineStage']), 'reject', 'danger', 'Rejected'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function withdrawApplication(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.manage-pipeline')) {
            return $this->denied('remove applications');
        }

        $application = $this->locateApplication($args, activeOnly: false);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No application found for that candidate.');
        }

        if ($application->isHired()) {
            return ToolResult::error("Checked {$application->applicant->full_name}", 'That application produced a hire and cannot be removed.');
        }

        $name = $application->applicant->full_name;
        $posting = $application->jobPosting;
        $card = $this->applicationCard($application, 'cancel', 'warning', 'Withdrawn');

        $application->delete();

        $this->log('deleted', "Removed {$name}'s application via assistant", $posting, $name);

        return ToolResult::ok("Removed {$name} from “{$posting?->title}”", null, $card);
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
            $employee = ApplicantHirer::hire($application, $user, (bool) ($args['send_invitation'] ?? true));
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

    // ── Interviews ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findInterviews(User $user, array $args): ToolResult
    {
        $when = strtolower(trim((string) ($args['when'] ?? 'upcoming')));
        $when = in_array($when, ['upcoming', 'past', 'today', 'all'], true) ? $when : 'upcoming';
        $result = in_array($args['result'] ?? null, InterviewScheduler::RESULTS, true) ? $args['result'] : null;
        $applicant = $this->firstFilled($args, ['applicant', 'candidate', 'name']);
        $interviewer = $this->firstFilled($args, ['interviewer', 'interviewer_name']);
        $posting = $this->locatePosting($args);

        $interviews = Interview::query()
            ->with(['application.applicant', 'application.jobPosting', 'interviewer'])
            ->when($applicant, fn (Builder $q) => $q->whereHas('application.applicant', fn (Builder $a) => $this->matchByTokens($a, $applicant)))
            ->when($interviewer, fn (Builder $q) => $q->whereHas('interviewer', fn (Builder $u) => $this->matchByTokens($u, $interviewer)))
            ->when($posting, fn (Builder $q) => $q->whereHas('application', fn (Builder $a) => $a->where('job_posting_id', $posting->id)))
            ->when($result, fn (Builder $q) => $q->where('result', $result))
            ->when($when === 'upcoming', fn (Builder $q) => $q->where('scheduled_at', '>=', now()))
            ->when($when === 'past', fn (Builder $q) => $q->where('scheduled_at', '<', now()))
            ->when($when === 'today', fn (Builder $q) => $q->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]))
            ->orderBy('scheduled_at', $when === 'past' ? 'desc' : 'asc')
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $interviews->map(fn (Interview $i): array => $this->interviewCard($i, 'find', 'neutral', ucfirst($i->result ?? 'pending')))->all();

        return ToolResult::found(
            $when === 'all' ? 'Listed interviews' : "Listed {$when} interviews",
            count($cards).' found',
            $cards,
        );
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

        $targetStage = null;
        if (filled($args['stage'] ?? null)) {
            $targetStage = $this->resolveStageByName($application->jobPosting->pipeline, $args['stage'], kind: 'open');

            if ($targetStage === null) {
                $openNames = $application->jobPosting->pipeline->stages->where('kind', 'open')->pluck('name')->implode(', ');

                return ToolResult::error('Scheduled the interview', "No open stage named “{$args['stage']}” — try one of: {$openNames}.");
            }
        }

        $data = [
            'interviewer_id' => $this->resolveInterviewer($args),
            'scheduled_at' => $this->dateTime($args['scheduled_at'] ?? null),
            'mode' => $args['mode'] ?? null,
            'location' => $args['location'] ?? null,
            'notes' => $args['notes'] ?? null,
        ];

        $validator = Validator::make($data, collect((new StoreInterviewRequest)->rules())->only(array_keys($data))->all());

        if ($validator->fails()) {
            return ToolResult::error('Validated the interview', $validator->errors()->first());
        }

        $interview = InterviewScheduler::book($application, $validator->validated(), $targetStage);

        $name = $application->applicant->full_name;
        $this->log('created', "Scheduled an interview for {$name} via assistant", $application->jobPosting, $name);

        $interview->setRelation('application', $application->fresh(['applicant', 'jobPosting', 'pipelineStage']));

        return ToolResult::ok(
            "Scheduled interview for {$name}",
            Carbon::parse($data['scheduled_at'])->format('M j, g:i A'),
            $this->interviewCard($interview->load('interviewer'), 'schedule', 'info', 'Scheduled'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateInterview(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.schedule-interviews')) {
            return $this->denied('manage interviews');
        }

        $interview = $this->locateInterview($args);
        if (! $interview) {
            return ToolResult::error('Looked up the interview', 'No interview found for that candidate.');
        }

        $changes = array_filter([
            'scheduled_at' => $this->dateTime($args['scheduled_at'] ?? null),
            'mode' => in_array($args['mode'] ?? null, StoreInterviewRequest::MODES, true) ? $args['mode'] : null,
            'location' => $args['location'] ?? null,
            'notes' => $args['notes'] ?? null,
            'result' => in_array($args['result'] ?? null, InterviewScheduler::RESULTS, true) ? $args['result'] : null,
            'feedback' => $args['feedback'] ?? null,
            'interviewer_id' => $this->resolveInterviewer($args),
        ], fn ($value): bool => $value !== null);

        if ($changes === []) {
            return ToolResult::error('Updated the interview', 'Tell me what to change — a new time, the mode, the interviewer, or the outcome.');
        }

        $interview->update($changes);

        $name = $interview->application?->applicant?->full_name ?? 'the candidate';
        $recorded = isset($changes['result']);

        $this->log(
            'updated',
            ($recorded ? "Recorded the interview outcome for {$name}" : "Rescheduled {$name}'s interview").' via assistant',
            $interview->application?->jobPosting,
            $name,
            $changes,
        );

        [$tone, $badge] = match ($changes['result'] ?? null) {
            'passed' => ['positive', 'Passed'],
            'failed' => ['danger', 'Failed'],
            default => ['info', 'Updated'],
        };

        return ToolResult::ok(
            $recorded ? "Recorded {$name}'s interview as {$changes['result']}" : "Rescheduled {$name}'s interview",
            $recorded ? ($changes['feedback'] ?? null) : Carbon::parse($interview->scheduled_at)->format('M j, g:i A'),
            $this->interviewCard($interview->fresh(['application.applicant', 'application.jobPosting', 'interviewer']), $recorded ? ($changes['result'] === 'failed' ? 'reject' : 'approve') : 'schedule', $tone, $badge),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function cancelInterview(User $user, array $args): ToolResult
    {
        if ($user->cannot('recruitment.schedule-interviews')) {
            return $this->denied('manage interviews');
        }

        $interview = $this->locateInterview($args);
        if (! $interview) {
            return ToolResult::error('Looked up the interview', 'No interview found for that candidate.');
        }

        $name = $interview->application?->applicant?->full_name ?? 'the candidate';
        $card = $this->interviewCard($interview, 'cancel', 'warning', 'Cancelled');

        $interview->delete();

        $this->log('deleted', "Cancelled {$name}'s interview via assistant", $interview->application?->jobPosting, $name);

        return ToolResult::ok("Cancelled {$name}'s interview", null, $card);
    }

    // ── Decision support ─────────────────────────────────────────────────────

    /**
     * How hiring is going — org-wide, or the health of one posting's pipeline.
     *
     * @param  array<string, mixed>  $args
     */
    private function recruitmentSummary(User $user, array $args): ToolResult
    {
        $posting = $this->locatePosting($args);

        if ($posting === null) {
            if (filled($this->firstFilled($args, ['posting', 'job_posting', 'posting_title', 'title']))) {
                return ToolResult::error('Looked up the posting', 'No matching job posting found.');
            }

            return $this->organisationSummary();
        }

        $posting->loadMissing('pipeline.stages');
        $applications = $this->scoredApplications($posting);
        $insights = $this->pipeline->build($applications, $posting->pipeline);
        $overall = $insights['overall'];

        $top = $overall['top'] ?? null;

        $meta = [
            $overall['avg_fit'] !== null ? "Avg fit {$overall['avg_fit']}" : null,
            $overall['strong'].' strong',
            $overall['ready'].' ready to advance',
            $overall['stalled'].' stalled',
            $top ? "Top: {$top['name']} ({$top['fit']})" : null,
        ];

        $subtitle = "{$overall['active']} active of {$overall['total']} applicants · {$overall['hired']} hired · {$overall['rejected']} rejected";

        return ToolResult::found(
            "Summarised the “{$posting->title}” pipeline",
            $subtitle,
            [$this->card(
                kind: 'insight',
                tone: $overall['stalled'] > 0 ? 'warning' : 'info',
                badge: 'Pipeline',
                title: $posting->title,
                subtitle: $subtitle,
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
                id: $posting->id,
            )],
        );
    }

    /**
     * Ranked shortlist for one posting, strongest fit first.
     *
     * @param  array<string, mixed>  $args
     */
    private function rankCandidates(User $user, array $args): ToolResult
    {
        $posting = $this->locatePosting($args);
        if (! $posting) {
            return ToolResult::error('Looked up the posting', 'No matching job posting found.');
        }

        $posting->loadMissing('pipeline.stages');
        $stage = $this->resolveStageByName($posting->pipeline, $args['stage'] ?? null);
        $limit = min(max((int) ($args['limit'] ?? self::RANK_DEFAULT), 1), self::RANK_MAX);

        $ranked = $this->scoredApplications($posting)
            ->when($stage !== null, fn ($apps) => $apps->where('recruitment_pipeline_stage_id', $stage->id))
            // Terminal cards keep their score but are out of contention, so an
            // unfiltered shortlist only ranks candidates still in the running.
            ->when($stage === null, fn ($apps) => $apps->filter(fn (JobApplication $a): bool => $a->isOpen()))
            ->sortByDesc(fn (JobApplication $a): int => $a->fit['value'])
            ->take($limit)
            ->values();

        $cards = $ranked->map(function (JobApplication $application, int $index): array {
            $applicant = $application->applicant;

            return $this->card(
                kind: 'insight',
                tone: match ($application->fit['band']) {
                    'strong' => 'positive',
                    'promising' => 'info',
                    'fair' => 'neutral',
                    default => 'warning',
                },
                badge: '#'.($index + 1),
                title: $applicant?->full_name ?? 'Candidate',
                subtitle: "Fit {$application->fit['value']}/100 · {$application->fit['band']}",
                meta: [ucfirst($application->pipelineStage->name), $application->recommendation['label']],
                avatar: $applicant
                    ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                    : null,
                id: $application->id,
            );
        })->all();

        return ToolResult::found(
            "Ranked candidates for “{$posting->title}”",
            count($cards).' shortlisted',
            $cards,
        );
    }

    /**
     * One candidate's decision-support snapshot — no model call.
     *
     * @param  array<string, mixed>  $args
     */
    private function candidateProfile(User $user, array $args): ToolResult
    {
        $application = $this->locateApplication($args, activeOnly: false);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No application found for that candidate.');
        }

        $applicant = $application->applicant;
        $score = $this->score($application);
        $recommendation = $this->scorer->recommendation($application, $score);

        $interviews = $application->interviews;
        $verdict = $interviews->firstWhere('result', 'passed') ? 'passed'
            : ($interviews->firstWhere('result', 'failed') ? 'did not pass' : 'pending');

        $meta = [
            "Fit {$score['value']}/100 · {$score['band']}",
            $this->rankLabel($application),
            $application->rating ? "Rated {$application->rating}/5" : 'Not yet rated',
            $interviews->isNotEmpty() ? $interviews->count().' interview'.($interviews->count() === 1 ? '' : 's').' · '.$verdict : 'No interviews yet',
            'Next: '.$recommendation['label'],
        ];

        $subtitle = collect([
            ucfirst($application->pipelineStage->name).' on '.($application->jobPosting?->title ?? 'a posting'),
            $applicant?->headline,
            $application->applied_at ? 'applied '.$application->applied_at->diffForHumans() : null,
        ])->filter()->implode(' · ');

        return ToolResult::found(
            "Reviewed {$applicant?->full_name}",
            $recommendation['hint'],
            [$this->card(
                kind: 'insight',
                tone: match ($score['band']) {
                    'strong' => 'positive',
                    'promising' => 'info',
                    'fair' => 'neutral',
                    default => 'warning',
                },
                badge: 'Profile',
                title: $applicant?->full_name ?? 'Candidate',
                subtitle: $subtitle,
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
                avatar: $applicant
                    ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                    : null,
                id: $application->id,
            )],
        );
    }

    /**
     * The LLM's grounded read of one candidate. Costs a model call, so the saved
     * read is reused unless a refresh was asked for — the same bargain the
     * candidate drawer makes.
     *
     * @param  array<string, mixed>  $args
     */
    private function candidateInsights(User $user, array $args): ToolResult
    {
        $application = $this->locateApplication($args, activeOnly: false);
        if (! $application) {
            return ToolResult::error('Looked up the candidate', 'No application found for that candidate.');
        }

        $name = $application->applicant?->full_name ?? 'the candidate';
        $refresh = (bool) ($args['refresh'] ?? false);
        $saved = $application->ai_insights;

        if (! $refresh && is_array($saved) && ($saved['available'] ?? false)) {
            return $this->insightResult($application, $saved, "Read the saved AI insights for {$name}");
        }

        $application->load([
            'applicant.documents',
            'jobPosting',
            'interviews' => fn ($query) => $query->latest('scheduled_at'),
        ]);

        $result = $this->insights->generate($application, $this->scorer->score($application, $application->jobPosting));

        if (! ($result['available'] ?? false)) {
            return ToolResult::error("Read {$name}’s documents", (string) ($result['reason'] ?? 'AI insights are unavailable right now.'));
        }

        $application->ai_insights = $result;
        $application->save();

        $this->log('updated', "Generated AI insights for {$name} via assistant", $application->jobPosting, $name);

        return $this->insightResult($application, $result, "Read {$name}’s résumé and documents");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The org-wide hiring picture, from the same statistics the board shows.
     */
    private function organisationSummary(): ToolResult
    {
        $stats = $this->statistics->toArray();
        $stalled = JobApplication::query()->stalled()->count();
        $expiring = JobPosting::query()
            ->where('status', 'open')
            ->whereNotNull('closing_date')
            ->whereDate('closing_date', '<=', now()->addWeek()->toDateString())
            ->count();

        $subtitle = "{$stats['open_postings']} open posting".($stats['open_postings'] === 1 ? '' : 's')
            .", {$stats['in_pipeline']} candidate".($stats['in_pipeline'] === 1 ? '' : 's').' in the pipeline';

        $meta = [
            $stats['final_stage'].' at their final stage',
            $stats['interviews_upcoming'].' interview'.($stats['interviews_upcoming'] === 1 ? '' : 's').' upcoming',
            $stats['hired_this_month'].' hired this month',
            $stalled > 0 ? "{$stalled} stalled" : null,
            $expiring > 0 ? "{$expiring} closing within a week" : null,
        ];

        return ToolResult::found(
            'Summarised recruitment',
            $subtitle,
            [$this->card(
                kind: 'insight',
                tone: $stalled > 0 ? 'warning' : 'info',
                badge: 'Overview',
                title: 'Recruitment overview',
                subtitle: $subtitle,
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
            )],
        );
    }

    /**
     * Wrap an insights payload (fresh or saved) into a result card.
     *
     * @param  array<string, mixed>  $insights
     */
    private function insightResult(JobApplication $application, array $insights, string $label): ToolResult
    {
        $applicant = $application->applicant;
        $headline = (string) ($insights['headline'] ?? 'Candidate insights');
        $summary = (string) ($insights['summary'] ?? '');

        $meta = [
            ($insights['strengths'][0] ?? null) ? 'Strength: '.$insights['strengths'][0] : null,
            ($insights['concerns'][0] ?? null) ? 'Concern: '.$insights['concerns'][0] : null,
            filled($insights['recommendation'] ?? null) ? (string) $insights['recommendation'] : null,
        ];

        return ToolResult::found(
            $label,
            $headline,
            [$this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'AI read',
                title: $applicant?->full_name ?? 'Candidate',
                subtitle: trim($headline.($summary !== '' ? ' — '.$summary : '')),
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
                avatar: $applicant
                    ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                    : null,
                id: $application->id,
            )],
        );
    }

    /**
     * Every application on a posting, scored and carrying its recommendation —
     * the same preparation the pipeline board does before ranking.
     *
     * @return EloquentCollection<int, JobApplication>
     */
    private function scoredApplications(JobPosting $posting): EloquentCollection
    {
        $posting->loadMissing('pipeline.stages');

        $applications = $posting->applications()
            ->with([
                'applicant' => fn ($query) => $query->withCount('documents'),
                'interviews:id,job_application_id,result',
                'pipelineStage',
            ])
            ->get();

        $applications->each(function (JobApplication $application) use ($posting): void {
            $application->setRelation('jobPosting', $posting);
            $application->fit = $this->scorer->score($application, $posting);
            $application->recommendation = $this->scorer->recommendation($application, $application->fit);
        });

        return $applications;
    }

    /**
     * Score one application, making sure the scorer has everything it grades on.
     *
     * @return array{value: int, band: string, breakdown: list<array<string, mixed>>}
     */
    private function score(JobApplication $application): array
    {
        $application->loadMissing(['jobPosting.pipeline.stages', 'interviews', 'pipelineStage']);

        if (! $application->relationLoaded('applicant') || $application->applicant?->documents_count === null) {
            $application->load(['applicant' => fn ($query) => $query->withCount('documents')]);
        }

        return $this->scorer->score($application, $application->jobPosting);
    }

    /**
     * Where this candidate sits among the posting's active candidates by fit.
     */
    private function rankLabel(JobApplication $application): ?string
    {
        if (! $application->isOpen() || $application->jobPosting === null) {
            return null;
        }

        $contenders = $this->scoredApplications($application->jobPosting)
            ->filter(fn (JobApplication $a): bool => $a->isOpen())
            ->sortByDesc(fn (JobApplication $a): int => $a->fit['value'])
            ->values();

        $position = $contenders->search(fn (JobApplication $a): bool => $a->id === $application->id);

        return $position === false ? null : 'Rank '.($position + 1).' of '.$contenders->count();
    }

    /**
     * Find a posting by title — an exact (case-insensitive) title wins, otherwise
     * fall back to the free-text search scope.
     *
     * @param  array<string, mixed>  $args
     */
    private function locatePosting(array $args): ?JobPosting
    {
        $needle = $this->firstFilled($args, ['posting', 'job_posting', 'posting_title', 'title']);

        if ($needle === null) {
            return null;
        }

        return JobPosting::query()->whereRaw('lower(title) = ?', [mb_strtolower($needle)])->latest()->first()
            ?? JobPosting::query()->search($needle)->latest()->first();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateApplicant(array $args): ?Applicant
    {
        $needle = $this->firstFilled($args, ['applicant', 'candidate', 'match', 'name']);

        if ($needle === null) {
            $first = $this->firstFilled($args, ['first_name']);
            $last = $this->firstFilled($args, ['last_name']);
            $needle = trim($first.' '.$last) ?: null;
        }

        return $needle !== null ? $this->matchByTokens(Applicant::query(), $needle)->first() : null;
    }

    /**
     * The candidate's application — their most recent active one, or (when
     * `$activeOnly` is off) their most recent one of any kind. A posting name in
     * the args narrows it, for a candidate who applied to several vacancies.
     *
     * @param  array<string, mixed>  $args
     */
    private function locateApplication(array $args, bool $activeOnly = true): ?JobApplication
    {
        $applicant = $this->locateApplicant($args);

        if (! $applicant) {
            return null;
        }

        $posting = $this->locatePosting($args);

        $applications = JobApplication::query()
            ->with(['applicant', 'jobPosting.pipeline.stages', 'pipelineStage'])
            ->where('applicant_id', $applicant->id)
            ->when($posting, fn (Builder $q) => $q->where('job_posting_id', $posting->id));

        $active = (clone $applications)->open()->latest('applied_at')->first();

        if ($active !== null || $activeOnly) {
            return $active;
        }

        return $applications->latest('applied_at')->first();
    }

    /**
     * The interview to act on for a candidate: their next upcoming one, else the
     * most recent one on record.
     *
     * @param  array<string, mixed>  $args
     */
    private function locateInterview(array $args): ?Interview
    {
        $application = $this->locateApplication($args, activeOnly: false);

        if (! $application) {
            return null;
        }

        $interviews = Interview::query()
            ->with(['application.applicant', 'application.jobPosting', 'interviewer'])
            ->where('job_application_id', $application->id);

        return (clone $interviews)->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->first()
            ?? $interviews->orderByDesc('scheduled_at')->first();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveInterviewer(array $args): ?int
    {
        $name = $this->firstFilled($args, ['interviewer', 'interviewer_name']);

        if ($name === null) {
            return null;
        }

        $id = $this->matchByTokens(User::query()->where('is_active', true), $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve a label (department name, position title) to an id, distinguishing
     * "not asked for" (null) from "asked for but unknown" (an error result), so a
     * typo never silently drops the field.
     *
     * @param  Builder<*>  $query
     */
    private function resolveLabel(Builder $query, string $column, ?string $value): int|ToolResult|null
    {
        if ($value === null) {
            return null;
        }

        $id = $this->resolveId($query, $column, $value);

        return $id ?? ToolResult::error('Looked up “'.$value.'”', 'Nothing matches “'.$value.'” — use one of the names I listed.');
    }

    /**
     * The applicant fields shared by every tool that can create or edit a
     * candidate, so the pool form and the assistant accept the same profile.
     *
     * @return array<string, array<string, mixed>>
     */
    private function applicantProperties(): array
    {
        return [
            'first_name' => ['type' => 'STRING'],
            'last_name' => ['type' => 'STRING'],
            'email' => ['type' => 'STRING'],
            'phone' => ['type' => 'STRING'],
            'current_location' => ['type' => 'STRING'],
            'headline' => ['type' => 'STRING', 'description' => 'e.g. Senior Backend Engineer.'],
            'linkedin_url' => ['type' => 'STRING'],
            'portfolio_url' => ['type' => 'STRING'],
            'years_experience' => ['type' => 'INTEGER'],
            'source' => ['type' => 'STRING', 'enum' => StoreApplicantRequest::SOURCES],
            'notes' => ['type' => 'STRING', 'description' => 'Recruiter notes.'],
        ];
    }

    /**
     * The scalar applicant rules (the file rules need an upload, which chat has
     * no way to provide).
     *
     * @return array<string, mixed>
     */
    private function applicantRules(): array
    {
        return collect((new StoreApplicantRequest)->rules())
            ->only(array_keys($this->applicantProperties()))
            ->all();
    }

    /**
     * Pull the applicant profile out of a tool call. With `$defaults` on (a
     * create) the required fields and `source` are always present; with it off
     * (an edit) only what was actually passed comes back.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function applicantData(array $args, bool $defaults = true): array
    {
        $data = [];

        foreach (array_keys($this->applicantProperties()) as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = $field === 'years_experience'
                    ? (filled($args[$field]) ? (int) $args[$field] : null)
                    : (is_string($args[$field]) ? trim($args[$field]) : $args[$field]);
            }
        }

        if (! in_array($data['source'] ?? null, StoreApplicantRequest::SOURCES, true)) {
            unset($data['source']);
        }

        if ($defaults) {
            $data['first_name'] ??= '';
            $data['last_name'] ??= '';
            $data['source'] ??= 'other';
        }

        return $data;
    }

    /**
     * The pool entry we already hold for this person — matched on email when we
     * have one, else on an exact first + last name.
     *
     * @param  array<string, mixed>  $data
     */
    private function existingApplicant(array $data): ?Applicant
    {
        if (filled($data['email'] ?? null)) {
            $byEmail = Applicant::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $data['email'])])->first();

            if ($byEmail) {
                return $byEmail;
            }
        }

        if (blank($data['first_name'] ?? null) || blank($data['last_name'] ?? null)) {
            return null;
        }

        return Applicant::query()
            ->whereRaw('lower(first_name) = ?', [mb_strtolower((string) $data['first_name'])])
            ->whereRaw('lower(last_name) = ?', [mb_strtolower((string) $data['last_name'])])
            ->first();
    }

    /**
     * Normalise a skills argument: the model may send a list or a comma-separated
     * string. An explicit empty value clears the criterion.
     *
     * @return list<string>|null
     */
    private function skills(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        $skills = array_values(array_filter(array_map(
            fn ($skill): string => trim((string) $skill),
            $items,
        )));

        return $skills === [] ? null : $skills;
    }

    private function normaliseStatus(mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, StoreJobPostingRequest::STATUSES, true) ? $status : null;
    }

    /**
     * The organisation's default pipeline (with its stages loaded) — the one
     * create_job_posting uses, and what guidance() describes as "the" pipeline
     * when talking generally. Individual postings may use a different one.
     */
    private function defaultPipeline(): ?RecruitmentPipeline
    {
        return RecruitmentPipeline::where('is_default', true)->with('stages')->first();
    }

    /**
     * Resolve a stage name the model sent against a specific pipeline's real
     * stages — case-insensitive exact match, optionally narrowed to one `kind`
     * (e.g. only `open` stages, for a move). Returns null when there's no
     * pipeline, no name, or nothing matches — the caller decides what that
     * means (not filtered vs. an error).
     */
    private function resolveStageByName(?RecruitmentPipeline $pipeline, ?string $name, ?string $kind = null): ?RecruitmentPipelineStage
    {
        $name = trim((string) $name);

        if ($pipeline === null || $name === '') {
            return null;
        }

        return $pipeline->stages
            ->when($kind !== null, fn ($stages) => $stages->where('kind', $kind))
            ->first(fn (RecruitmentPipelineStage $stage): bool => mb_strtolower($stage->name) === mb_strtolower($name));
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
     * Record a recruitment mutation, tagged so the audit trail shows the
     * assistant did it.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, ?Model $subject, string $label, array $properties = []): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            properties: $properties,
            logName: 'recruitment',
            subjectLabel: $label,
        );
    }

    // ── Cards ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function postingCard(JobPosting $posting, string $kind, string $tone, string $badge): array
    {
        $subtitle = collect([$posting->position?->title, $posting->department?->name])->filter()->implode(' · ');
        $open = $posting->open_count ?? null;
        $days = $posting->daysToClose();

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $posting->title,
            subtitle: $subtitle ?: ucfirst(str_replace('_', ' ', (string) $posting->employment_type)),
            meta: [
                ucfirst($posting->status),
                $posting->openings.' opening'.($posting->openings === 1 ? '' : 's'),
                $open !== null ? $open.' active' : null,
                match (true) {
                    $days === null => null,
                    $days < 0 => 'closed '.abs($days).'d ago',
                    $days === 0 => 'closes today',
                    default => "closes in {$days}d",
                },
            ],
            id: $posting->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applicantCard(Applicant $applicant, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $applicant->full_name,
            subtitle: $applicant->headline ?: $applicant->email,
            meta: [
                $applicant->years_experience !== null ? $applicant->years_experience.' yr'.($applicant->years_experience === 1 ? '' : 's') : null,
                ucfirst(str_replace('_', ' ', (string) $applicant->source)),
                isset($applicant->applications_count) ? $applicant->applications_count.' application'.($applicant->applications_count === 1 ? '' : 's') : null,
            ],
            avatar: ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null],
            id: $applicant->id,
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
            meta: [
                ucfirst($application->pipelineStage->name),
                $application->rating ? $application->rating.'/5' : null,
            ],
            avatar: $applicant
                ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                : null,
            id: $application->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function interviewCard(Interview $interview, string $kind, string $tone, string $badge): array
    {
        $applicant = $interview->application?->applicant;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $applicant?->full_name ?? 'Candidate',
            subtitle: $interview->application?->jobPosting?->title,
            meta: [
                $interview->scheduled_at?->format('M j, g:i A'),
                ucfirst((string) $interview->mode),
                $interview->interviewer?->full_name,
            ],
            avatar: $applicant
                ? ['name' => $applicant->full_name, 'initials' => $applicant->initials(), 'photo' => null]
                : null,
            id: $interview->id,
        );
    }
}
