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
use App\Models\JobPosting;
use App\Models\Position;
use App\Models\User;
use App\Queries\JobPostingsIndexQuery;
use App\Queries\RecruitmentStatistics;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request, JobPosting $jobPosting): Response
    {
        $jobPosting->load(['department:id,name,code', 'position:id,title', 'postedBy:id,first_name,middle_name,last_name,suffix'])
            ->loadCount([
                'applications',
                'applications as open_count' => fn ($query) => $query->whereNotIn('stage', ['hired', 'rejected']),
                'applications as hired_count' => fn ($query) => $query->where('stage', 'hired'),
            ]);

        $applications = $jobPosting->applications()
            ->with(['applicant', 'hiredEmployee:id,first_name,middle_name,last_name,suffix,employee_no'])
            ->withCount('interviews')
            ->orderByDesc('rating')
            ->orderByDesc('applied_at')
            ->get();

        return Inertia::render('recruitment/pipeline', [
            'posting' => (new JobPostingResource($jobPosting))->resolve($request),
            'applications' => JobApplicationResource::collection($applications)->resolve($request),
            'options' => $this->pipelineOptions($jobPosting),
            'can' => $this->permissions($request),
        ]);
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
