<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobPostingScreeningQuestion;
use App\Models\Organization;
use App\Models\Position;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Models\User;
use App\Support\ApplicantHirer;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo recruitment: the tenant's own **pipelines** (ADR 0029), postings that use
 * them, an applicant pool, applications spread across each posting's own stages,
 * interviews, and a filled posting whose hire is already on the roster.
 *
 * Two pipelines are seeded deliberately, because one would hide the whole point
 * of the feature: a classic office flow and a shorter operations flow whose
 * stages are named nothing like it ("Trial Shift", "Not Selected"). Nothing here
 * — or anywhere else in the module — reads a stage's *name*; applications are
 * placed by the stage's `kind` and `position` within its own pipeline, so a
 * pipeline can be renamed end to end and the demo data still makes sense.
 *
 * Tenant-aware and idempotent: only seeds when the tenant has no postings yet.
 */
class RecruitmentSeeder extends Seeder
{
    /**
     * The pipelines this tenant hires through: name => [is_default, stages].
     *
     * @var array<string, array{default: bool, stages: list<array{0: string, 1: string}>}>
     */
    private const PIPELINES = [
        'Standard Hiring' => [
            'default' => true,
            'stages' => [
                ['Applied', 'open'],
                ['Screening', 'open'],
                ['Interview', 'open'],
                ['Offer', 'open'],
                ['Hired', 'won'],
                ['Rejected', 'lost'],
            ],
        ],
        // Deliberately unlike the standard flow — a shorter process with its own
        // vocabulary, so the board, the scorer and the assistant are demoable
        // against a pipeline that shares no stage names with the classic one.
        'Operations Hiring' => [
            'default' => false,
            'stages' => [
                ['Applied', 'open'],
                ['Trial Shift', 'open'],
                ['Supervisor Sign-off', 'open'],
                ['Onboarded', 'won'],
                ['Not Selected', 'lost'],
                ['Withdrew', 'lost'],
            ],
        ],
    ];

    /**
     * The postings, each pinned to a pipeline so the demo is stable rather than
     * random: position title => posting shape.
     *
     * `stage_offsets` distributes this posting's applications over its pipeline's
     * *open* stages by index (0 = the entry stage), with `lost` for a rejection —
     * never by stage name.
     *
     * @var list<array{title: string, department: string, pipeline: string, status: string, openings: int, resume: bool, scoring: bool, experience: int|null, skills: list<string>, questions: list<string>, applicants: int, offsets: list<int|string>}>
     */
    private const POSTINGS = [
        [
            'title' => 'Software Engineer',
            'department' => 'IT',
            'pipeline' => 'Standard Hiring',
            'status' => 'open',
            'openings' => 2,
            'resume' => true,
            'scoring' => true,
            'experience' => 3,
            'skills' => ['PHP', 'Laravel', 'React', 'PostgreSQL'],
            'questions' => [],
            'applicants' => 7,
            'offsets' => [0, 0, 1, 1, 2, 3, 'lost'],
        ],
        [
            'title' => 'Accountant',
            'department' => 'FIN',
            'pipeline' => 'Standard Hiring',
            'status' => 'open',
            'openings' => 1,
            'resume' => true,
            'scoring' => true,
            'experience' => 2,
            'skills' => ['Bookkeeping', 'Payroll', 'BIR compliance'],
            'questions' => ['Are you a licensed CPA?'],
            'applicants' => 5,
            'offsets' => [0, 1, 1, 2, 'lost'],
        ],
        [
            // The generic case: no résumé, no automatic ranking, and the criteria
            // that matter asked as plain yes/no questions instead.
            'title' => 'Operations Associate',
            'department' => 'OPS',
            'pipeline' => 'Operations Hiring',
            'status' => 'open',
            'openings' => 3,
            'resume' => false,
            'scoring' => false,
            'experience' => null,
            'skills' => [],
            'questions' => [
                'Do you have a valid driver’s license?',
                'Are you available for night shift?',
                'Can you lift 20kg unassisted?',
            ],
            'applicants' => 6,
            'offsets' => [0, 0, 1, 1, 2, 'lost'],
        ],
        [
            // Already filled — its hire is on the roster, which is what the
            // recruitment → workforce bridge produces.
            'title' => 'Recruiter',
            'department' => 'HR',
            'pipeline' => 'Standard Hiring',
            'status' => 'filled',
            'openings' => 1,
            'resume' => true,
            'scoring' => true,
            'experience' => 1,
            'skills' => ['Sourcing', 'Interviewing'],
            'questions' => [],
            'applicants' => 4,
            'offsets' => ['won', 'lost', 'lost', 2],
        ],
        [
            // Not published yet, so the postings grid shows the full lifecycle.
            'title' => 'Marketing Specialist',
            'department' => 'SAL',
            'pipeline' => 'Standard Hiring',
            'status' => 'draft',
            'openings' => 1,
            'resume' => true,
            'scoring' => true,
            'experience' => 2,
            'skills' => ['Content', 'SEO', 'Campaigns'],
            'questions' => [],
            'applicants' => 0,
            'offsets' => [],
        ],
    ];

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

        // Pipelines are configuration, not demo traffic — seed them even for a
        // tenant that already has postings (a fresh organisation starts with none).
        $pipelines = $this->seedPipelines();

        if (JobPosting::count() > 0) {
            return;
        }

        $departments = Department::query()->with('positions')->get()->keyBy('code');

        if ($departments->isEmpty()) {
            return;
        }

        $poster = User::query()->orderBy('id')->first();

        // A couple more candidates than the postings between them draw, so the
        // Candidate Pool tab also shows people not yet on any posting.
        $pool = Applicant::factory()->count(24)->create()->values();
        $taken = 0;

        foreach (self::POSTINGS as $config) {
            $pipeline = $pipelines[$config['pipeline']];
            $department = $departments->get($config['department']);
            $position = $department?->positions->firstWhere('title', $config['title'])
                ?? $department?->positions->first();

            $posting = $this->seedPosting($config, $pipeline, $department, $position, $poster);

            // Each posting draws its own slice of the pool, so the same applicant
            // never appears twice on one posting.
            $candidates = $pool->slice($taken, $config['applicants'])->values();
            $taken += $config['applicants'];

            $this->seedApplications($posting, $pipeline, $candidates, $config['offsets']);
        }
    }

    /**
     * The tenant's pipelines and their ordered stages. Idempotent on the
     * pipeline's name; a pipeline that already exists keeps the stages it has
     * (they may carry live applications).
     *
     * @return Collection<string, RecruitmentPipeline>
     */
    private function seedPipelines(): Collection
    {
        return collect(self::PIPELINES)->map(function (array $config, string $name): RecruitmentPipeline {
            $pipeline = RecruitmentPipeline::firstOrCreate(
                ['name' => $name],
                ['is_default' => $config['default']],
            );

            if ($pipeline->stages()->doesntExist()) {
                foreach ($config['stages'] as $position => [$stageName, $kind]) {
                    $pipeline->stages()->create([
                        'name' => $stageName,
                        'kind' => $kind,
                        'position' => $position,
                    ]);
                }
            }

            return $pipeline->load('stages');
        });
    }

    /**
     * One posting, its screening criteria and its own yes/no questions.
     *
     * @param  array<string, mixed>  $config
     */
    private function seedPosting(
        array $config,
        RecruitmentPipeline $pipeline,
        ?Department $department,
        ?Position $position,
        ?User $poster,
    ): JobPosting {
        $posting = JobPosting::factory()->create([
            'recruitment_pipeline_id' => $pipeline->id,
            'title' => $config['title'],
            'department_id' => $department?->id,
            'position_id' => $position?->id,
            'posted_by' => $poster?->id,
            'status' => $config['status'],
            'openings' => $config['openings'],
            'requires_resume' => $config['resume'],
            'use_fit_scoring' => $config['scoring'],
            'min_years_experience' => $config['experience'],
            'skills' => $config['skills'] ?: null,
            // Only a published posting is given a closing date; a draft has not
            // been committed to a date yet.
            'closing_date' => $config['status'] === 'open' ? now()->addWeeks(6)->toDateString() : null,
        ]);

        foreach ($config['questions'] as $index => $label) {
            JobPostingScreeningQuestion::factory()->create([
                'job_posting_id' => $posting->id,
                'label' => $label,
                'position' => $index,
            ]);
        }

        return $posting->load('screeningQuestions');
    }

    /**
     * Place this posting's candidates on its pipeline's stages, answer its
     * screening questions, and schedule an interview for anyone who has moved
     * past the entry stage.
     *
     * @param  Collection<int, Applicant>  $candidates
     * @param  list<int|string>  $offsets  index into the pipeline's open stages, or 'won'/'lost'
     */
    private function seedApplications(
        JobPosting $posting,
        RecruitmentPipeline $pipeline,
        Collection $candidates,
        array $offsets,
    ): void {
        $open = $pipeline->stages->where('kind', 'open')->values();

        foreach ($candidates as $index => $applicant) {
            $offset = $offsets[$index] ?? 0;
            $stage = $this->resolveStage($pipeline, $open, $offset);

            if (! $stage) {
                continue;
            }

            $application = JobApplication::factory()->create([
                'job_posting_id' => $posting->id,
                'applicant_id' => $applicant->id,
                'recruitment_pipeline_stage_id' => $stage->id,
                'screening_answers' => $this->answers($posting, $index),
                'rejected_reason' => $stage->kind === 'lost' ? 'Stronger candidates elsewhere in the pipeline' : null,
                'decided_at' => $stage->isTerminal() ? now()->subDays(3) : null,
            ]);

            if ($stage->kind === 'won') {
                $this->linkHire($application, $posting);
            }

            // Anyone the team has actually met has an interview on file: past the
            // entry stage, still in play.
            if ($stage->kind === 'open' && $stage->position > $open->first()->position) {
                Interview::factory()->create([
                    'job_application_id' => $application->id,
                    'result' => $stage->position >= $open->last()->position ? 'passed' : 'pending',
                ]);
            }
        }
    }

    /**
     * Turn an offset into a real stage of this pipeline: an index into its open
     * stages, or its `won` / first `lost` stage.
     *
     * @param  Collection<int, RecruitmentPipelineStage>  $open
     */
    private function resolveStage(RecruitmentPipeline $pipeline, Collection $open, int|string $offset): ?RecruitmentPipelineStage
    {
        return match ($offset) {
            'won' => $pipeline->wonStage(),
            'lost' => $pipeline->defaultLostStage(),
            default => $open->get(min((int) $offset, max($open->count() - 1, 0))),
        };
    }

    /**
     * Answers to the posting's screening questions, keyed by question id — the
     * shape the public application form submits. Deterministic, and not all yes,
     * so the candidate record has something to actually show.
     *
     * @return array<int, bool>|null
     */
    private function answers(JobPosting $posting, int $index): ?array
    {
        if ($posting->screeningQuestions->isEmpty()) {
            return null;
        }

        return $posting->screeningQuestions
            ->mapWithKeys(fn (JobPostingScreeningQuestion $question, int $i): array => [
                $question->id => ($index + $i) % 3 !== 0,
            ])
            ->all();
    }

    /**
     * A hired application points at the employee it produced — the same end state
     * {@see ApplicantHirer} leaves behind. Adopts an existing roster
     * line rather than creating one, so headcount stays as the org seeder set it.
     */
    private function linkHire(JobApplication $application, JobPosting $posting): void
    {
        $employee = Employee::query()
            ->where('department_id', $posting->department_id)
            ->whereNotIn('id', JobApplication::query()->whereNotNull('hired_employee_id')->pluck('hired_employee_id'))
            ->orderByDesc('date_hired')
            ->first();

        if (! $employee) {
            return;
        }

        $application->update(['hired_employee_id' => $employee->id]);
    }
}
