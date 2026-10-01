<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreApplicantRequest;
use App\Http\Requests\Recruitment\UpdateApplicantRequest;
use App\Models\Applicant;
use App\Support\ActivityLogger;
use App\Support\ApplicantDocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class ApplicantController extends Controller
{
    /**
     * Add an applicant to the candidate pool.
     */
    public function store(StoreApplicantRequest $request): RedirectResponse
    {
        $applicant = new Applicant(Arr::except($request->validated(), ['resume', 'documents']));

        if ($request->hasFile('resume')) {
            $applicant->resume = $request->file('resume')->store('applicant-resumes', 'public');
        }

        $applicant->save();

        ApplicantDocumentStore::store($applicant, $request->validated('documents') ?? [], $request->user()->id);

        ActivityLogger::log(
            event: 'created',
            description: "Added applicant {$applicant->full_name} to the pool",
            subject: $applicant,
            logName: 'recruitment',
            subjectLabel: $applicant->full_name,
        );

        return $this->respond('Applicant added.');
    }

    /**
     * Update an applicant's profile.
     */
    public function update(UpdateApplicantRequest $request, Applicant $applicant): RedirectResponse
    {
        $applicant->fill(Arr::except($request->validated(), ['resume', 'documents']));

        if ($request->hasFile('resume')) {
            ApplicantDocumentStore::forgetResume($applicant);
            $applicant->resume = $request->file('resume')->store('applicant-resumes', 'public');
        }

        $applicant->save();

        ApplicantDocumentStore::store($applicant, $request->validated('documents') ?? [], $request->user()->id);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated applicant {$applicant->full_name}",
            subject: $applicant,
            logName: 'recruitment',
            subjectLabel: $applicant->full_name,
        );

        return $this->respond('Applicant updated.');
    }

    /**
     * Remove an applicant (and their applications) from the pool.
     */
    public function destroy(Applicant $applicant): RedirectResponse
    {
        $label = $applicant->full_name;
        ApplicantDocumentStore::purge($applicant);

        $applicant->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Removed applicant {$label}",
            logName: 'recruitment',
            subjectLabel: $label,
        );

        return $this->respond('Applicant removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
