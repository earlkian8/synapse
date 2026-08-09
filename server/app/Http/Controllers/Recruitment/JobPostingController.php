<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Http\Requests\Recruitment\UpdateJobPostingRequest;
use App\Http\Resources\ApplicantResource;
use App\Http\Resources\JobApplicationResource;
use App\Http\Resources\JobPostingResource;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Position;
use App\Models\User;
use App\Queries\JobPostingsIndexQuery;
use App\Queries\RecruitmentStatistics;
use App\Support\ActivityLogger;
use App\Support\Recruitment\ApplicantScorer;
use App\Support\Recruitment\PipelineInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class JobPostingController extends Controller
{
    /**
     * Display the job-postings board.
     */
    public function index(Request $request, JobPostingsIndexQuery $query, RecruitmentStatistics $statistics): Response
    {
        [$sort, $direction] = $query->sort($request);

        return Inertia::render('recruitment/index', [
            'postings' => JobPostingResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'options' => $this->formOptions(),
            'can' => $this->permissions($request),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
                'department' => $request->integer('department') ?: null,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Display a posting's hiring pipeline (the ATS board).
     */
    public function show(Request $request, JobPosting $jobPosting, ApplicantScorer $scorer, PipelineInsights $insights): Response
    {
        $jobPosting->load(['department:id,name,code', 'position:id,title', 'postedBy:id,first_name,middle_name,last_name,suffix'])
            ->loadCount([
                'applications',
                'applications as open_count' => fn ($query) => $query->open(),
                'applications as hired_count' => fn ($query) => $query->where('stage', 'hired'),
            ]);

        $applications = $jobPosting->applications()
            ->with([
                'applicant' => fn ($query) => $query->withCount('documents'),
                'hiredEmployee:id,first_name,middle_name,last_name,suffix,employee_no',
                'interviews:id,job_application_id,result',
            ])
            ->withCount('interviews')
            ->get();

        $applications = $this->withFitScores($applications, $jobPosting, $scorer);

        return Inertia::render('recruitment/pipeline', [
            'posting' => (new JobPostingResource($jobPosting))->resolve($request),
            'applications' => JobApplicationResource::collection($applications)->resolve($request),
            'insights' => $insights->build($applications),
            'options' => $this->pipelineOptions($jobPosting),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Score each application, rank the active candidates by fit, and return the
     * collection ordered so the strongest still-in-the-running candidates lead.
     *
     * @param  Collection<int, JobApplication>  $applications
     * @return Collection<int, JobApplication>
     */
    private function withFitScores($applications, JobPosting $posting, ApplicantScorer $scorer)
    {
        $applications->each(function (JobApplication $application) use ($scorer, $posting): void {
            $score = $scorer->score($application, $posting);
            $application->fit = $score;
            $application->recommendation = $scorer->recommendation($application, $score);

            // The interviews were loaded only to feed the scorer; drop the
            // relation so the list payload stays lean (interviews_count remains).
            $application->unsetRelation('interviews');
        });

        // Rank only the candidates still in the running (a hired/rejected card
        // keeps its score but is out of contention).
        $contenders = $applications
            ->filter(fn (JobApplication $application): bool => $application->isOpen())
            ->sortByDesc(fn (JobApplication $application): int => $application->fit['value'])
            ->values();

        $total = $contenders->count();
        $contenders->each(function (JobApplication $application, int $index) use ($total): void {
            $application->fit_rank = ['position' => $index + 1, 'total' => $total];
        });

        // Surface the strongest open candidates first; terminal cards sink below.
        return $applications
            ->sortByDesc(fn (JobApplication $application): string => sprintf(
                '%d-%03d-%011d',
                $application->isOpen() ? 1 : 0,
                $application->fit['value'],
                $application->applied_at?->timestamp ?? 0,
            ))
            ->values();
    }

    /**
     * Store a newly created job posting.
     */
    public function store(StoreJobPostingRequest $request): RedirectResponse
    {
        $posting = JobPosting::create([
            ...$request->validated(),
            'posted_by' => $request->user()->id,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Created job posting \"{$posting->title}\"",
            subject: $posting,
            logName: 'recruitment',
            subjectLabel: $posting->title,
        );

        return $this->respond('Job posting created.');
    }

    /**
     * Update the given job posting.
     */
    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): RedirectResponse
    {
        $jobPosting->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated job posting \"{$jobPosting->title}\"",
            subject: $jobPosting,
            logName: 'recruitment',
            subjectLabel: $jobPosting->title,
        );

        return $this->respond('Job posting updated.');
    }

    /**
     * Delete a job posting (and its applications).
     */
    public function destroy(JobPosting $jobPosting): RedirectResponse
    {
        $title = $jobPosting->title;
        $jobPosting->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted job posting \"{$title}\"",
            logName: 'recruitment',
            subjectLabel: $title,
        );

        return $this->respond('Job posting deleted.');
    }

    /**
     * Permission flags shared with the front-end.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'create' => $user->can('recruitment.create'),
            'update' => $user->can('recruitment.update'),
            'delete' => $user->can('recruitment.delete'),
            'managePipeline' => $user->can('recruitment.manage-pipeline'),
            'scheduleInterviews' => $user->can('recruitment.schedule-interviews'),
            'hire' => $user->can('recruitment.hire'),
            'export' => $user->can('recruitment.export'),
        ];
    }

    /**
     * Select options for the postings list + create/edit form.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name', 'code']),
            'positions' => Position::orderBy('title')->get(['id', 'title', 'department_id']),
        ];
    }

    /**
     * Select options for the pipeline board (interviewers + candidate pool).
     *
     * @return array<string, mixed>
     */
    private function pipelineOptions(JobPosting $posting): array
    {
        $appliedIds = $posting->applications()->pluck('applicant_id');

        return [
            'interviewers' => User::query()
                ->where('is_active', true)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (User $u): array => ['id' => $u->id, 'full_name' => $u->full_name])
                ->all(),
            'applicants' => ApplicantResource::collection(
                Applicant::query()
                    ->whereNotIn('id', $appliedIds)
                    ->orderBy('first_name')
                    ->limit(100)
                    ->get()
            )->resolve(),
        ];
    }

    /**
     * Flash a toast and bounce back.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
