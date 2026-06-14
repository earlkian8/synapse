<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class JobApplicationController extends Controller
{
    /**
     * Stages an application may be moved to from the board (terminal stages
     * "hired" and "rejected" have dedicated actions).
     *
     * @var list<string>
     */
    private const MOVABLE_STAGES = ['applied', 'screening', 'interview', 'offer'];

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
            'stage' => 'applied',
            'expected_salary' => $data['expected_salary'] ?? null,
            'cover_note' => $data['cover_note'] ?? null,
            'rating' => $data['rating'] ?? null,
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
     * Return a single application with everything the detail drawer needs.
     */
    public function show(JobApplication $application): JobApplicationResource
    {
        $application->load([
            'applicant.documents',
            'jobPosting:id,title',
            'hiredEmployee:id,first_name,middle_name,last_name,suffix,employee_no',
            'interviews' => fn ($query) => $query->with('interviewer:id,first_name,middle_name,last_name,suffix')->latest('scheduled_at'),
        ]);

        return new JobApplicationResource($application);
    }

    /**
     * Move an application to another (non-terminal) pipeline stage.
     */
    public function stage(Request $request, JobApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'stage' => ['required', Rule::in(self::MOVABLE_STAGES)],
        ]);

        $application->update([
            'stage' => $validated['stage'],
            'rejected_reason' => null,
            'decided_at' => null,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Moved {$application->applicant->full_name} to {$validated['stage']}",
            subject: $application->jobPosting,
            properties: ['stage' => $validated['stage']],
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        return $this->respond('Candidate moved to '.$validated['stage'].'.');
    }

    /**
     * Reject an application, recording an optional reason.
     */
    public function reject(Request $request, JobApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $application->update([
            'stage' => 'rejected',
            'rejected_reason' => $validated['reason'] ?? null,
            'decided_at' => now(),
        ]);

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
