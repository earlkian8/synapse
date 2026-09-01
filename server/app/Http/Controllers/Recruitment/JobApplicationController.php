<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruitmentPipelineStage;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\Recruitment\ApplicantInsights;
use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class JobApplicationController extends Controller
{
    /**
     * Add a candidate to a posting's pipeline — picking an existing applicant or
     * creating a new one inline.
     */
    public function store(StoreJobApplicationRequest $request, JobPosting $jobPosting): RedirectResponse
    {
        $data = $request->validated();
        $applicant = $this->resolveApplicant($data);

        if ($jobPosting->applications()->where('applicant_id', $applicant->id)->exists()) {
            return $this->respond('That candidate is already in this pipeline.', 'warning');
        }

        $jobPosting->applications()->create([
            'applicant_id' => $applicant->id,
            'recruitment_pipeline_stage_id' => $jobPosting->pipeline->entryStage()->id,
            'expected_salary' => $data['expected_salary'] ?? null,
            'cover_note' => $data['cover_note'] ?? null,
            'rating' => $data['rating'] ?? null,
            'screening_answers' => $data['screening_answers'] ?? null,
            'applied_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "{$applicant->full_name} applied for \"{$jobPosting->title}\"",
            subject: $jobPosting,
            logName: 'recruitment',
            subjectLabel: $jobPosting->title,
        );

        Notifier::toRole(
            'hr-manager',
            'New application',
            "{$applicant->full_name} applied for {$jobPosting->title}.",
            url: '/recruitment/'.$jobPosting->getRouteKey(),
            category: 'recruitment',
            actor: $request->user(),
        );

        return $this->respond('Candidate added to the pipeline.');
    }

    /**
     * Return a single application with everything the detail drawer needs — the
     * full candidate profile, interview history, fit score + recommendation, and
     * the candidate's other applications across postings.
     */
    public function show(JobApplication $application, ApplicantScorer $scorer): JobApplicationResource
    {
        $application->load([
            'applicant.documents',
            'jobPosting.pipeline.stages',
            'jobPosting.screeningQuestions',
            'pipelineStage',
            'hiredEmployee:id,first_name,middle_name,last_name,suffix,employee_no',
            'interviews' => fn ($query) => $query->with('interviewer:id,first_name,middle_name,last_name,suffix')->latest('scheduled_at'),
        ]);

        if ($application->jobPosting->use_fit_scoring) {
            $score = $scorer->score($application, $application->jobPosting);
            $application->fit = $score;
            $application->recommendation = $scorer->recommendation($application, $score);
        }

        $application->other_applications = $this->otherApplications($application);

        return new JobApplicationResource($application);
    }

    /**
     * Generate (and persist) LLM decision-support insights for one application —
     * the model reads the candidate's actual résumé and supporting documents and
     * returns a grounded read for HR. The result is saved on the application so
     * reopening the drawer shows it without spending another model call.
     */
    public function insights(JobApplication $application, ApplicantScorer $scorer, ApplicantInsights $insights): JsonResponse
    {
        $application->load([
            'applicant.documents',
            'jobPosting:id,title,description,requirements,min_years_experience,skills,requires_resume',
            'interviews' => fn ($query) => $query->latest('scheduled_at'),
        ]);

        $score = $scorer->score($application, $application->jobPosting);
        $result = $insights->generate($application, $score);

        if ($result['available'] ?? false) {
            $application->ai_insights = $result;
            $application->save();

            ActivityLogger::log(
                event: 'updated',
                description: "Generated AI insights for {$application->applicant->full_name}",
                subject: $application->jobPosting,
                logName: 'recruitment',
                subjectLabel: $application->applicant->full_name,
            );
        }

        return response()->json(['insights' => $result]);
    }

    /**
     * The candidate's other applications (different postings) so HR sees the
     * whole picture without leaving the drawer.
     *
     * @return list<array<string, mixed>>
     */
    private function otherApplications(JobApplication $application): array
    {
        return JobApplication::query()
            ->where('applicant_id', $application->applicant_id)
            ->whereKeyNot($application->id)
            ->with(['jobPosting:id,title,status', 'pipelineStage:id,name,kind'])
            ->latest('applied_at')
            ->get()
            ->map(fn (JobApplication $other): array => [
                'id' => $other->id,
                'stage' => $other->pipelineStage->name,
                'stage_kind' => $other->pipelineStage->kind,
                'rating' => $other->rating,
                'posting' => $other->jobPosting?->title,
                'posting_status' => $other->jobPosting?->status,
                'applied_human' => $other->applied_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Move an application to another open (non-terminal) stage of its own
     * pipeline. Terminal stages have dedicated actions, so a `won`/`lost` target
     * is refused here.
     */
    public function stage(Request $request, JobApplication $application): RedirectResponse
    {
        $pipeline = $application->jobPosting->pipeline;

        $validated = $request->validate([
            'stage_id' => [
                'required',
                'integer',
                Rule::exists('recruitment_pipeline_stages', 'id')
                    ->where('recruitment_pipeline_id', $pipeline->id)
                    ->where('kind', 'open'),
            ],
        ]);

        $stage = RecruitmentPipelineStage::findOrFail($validated['stage_id']);
        $application->moveTo($stage);

        ActivityLogger::log(
            event: 'updated',
            description: "Moved {$application->applicant->full_name} to {$stage->name}",
            subject: $application->jobPosting,
            properties: ['stage' => $stage->name],
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        return $this->respond('Candidate moved to '.$stage->name.'.');
    }

    /**
     * Reject an application, recording an optional reason. Defaults to the
     * pipeline's primary "lost" stage; a pipeline with several (e.g. "Rejected"
     * and "Withdrawn") lets the caller name which one.
     */
    public function reject(Request $request, JobApplication $application): RedirectResponse
    {
        $pipeline = $application->jobPosting->pipeline;

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'lost_stage_id' => [
                'nullable',
                'integer',
                Rule::exists('recruitment_pipeline_stages', 'id')
                    ->where('recruitment_pipeline_id', $pipeline->id)
                    ->where('kind', 'lost'),
            ],
        ]);

        $lostStage = isset($validated['lost_stage_id'])
            ? RecruitmentPipelineStage::findOrFail($validated['lost_stage_id'])
            : null;

        $application->rejectWith($lostStage, $validated['reason'] ?? null);

        ActivityLogger::log(
            event: 'updated',
            description: "Rejected {$application->applicant->full_name}",
            subject: $application->jobPosting,
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        return $this->respond('Application rejected.');
    }

    /**
     * Update the recruiter-facing fields on an application (rating, notes, ask).
     */
    public function update(Request $request, JobApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cover_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $application->update($validated);

        return $this->respond('Application updated.');
    }

    /**
     * Remove an application from the pipeline.
     */
    public function destroy(JobApplication $application): RedirectResponse
    {
        $application->delete();

        return $this->respond('Application removed.');
    }

    /**
     * Pick the existing applicant or create one from the inline fields.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveApplicant(array $data): Applicant
    {
        if (! empty($data['applicant_id'])) {
            return Applicant::findOrFail($data['applicant_id']);
        }

        return Applicant::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'current_location' => $data['current_location'] ?? null,
            'headline' => $data['headline'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'portfolio_url' => $data['portfolio_url'] ?? null,
            'years_experience' => $data['years_experience'] ?? null,
            'source' => $data['source'] ?? 'other',
        ]);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
