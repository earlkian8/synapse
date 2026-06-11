<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreApplicantRequest;
use App\Http\Requests\Recruitment\UpdateApplicantRequest;
use App\Models\Applicant;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ApplicantController extends Controller
{
    /**
     * Add an applicant to the candidate pool.
     */
    public function store(StoreApplicantRequest $request): RedirectResponse
    {
        $applicant = new Applicant(Arr::except($request->validated(), ['resume']));

        if ($request->hasFile('resume')) {
            $applicant->resume = $request->file('resume')->store('applicant-resumes', 'public');
        }

        $applicant->save();

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
        $applicant->fill(Arr::except($request->validated(), ['resume']));

        if ($request->hasFile('resume')) {
            $this->deleteResume($applicant);
            $applicant->resume = $request->file('resume')->store('applicant-resumes', 'public');
        }

        $applicant->save();

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
        $this->deleteResume($applicant);
        $applicant->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Removed applicant {$label}",
            logName: 'recruitment',
            subjectLabel: $label,
        );

        return $this->respond('Applicant removed.');
    }

    /**
     * Remove an applicant's résumé from disk, if any.
     */
    private function deleteResume(Applicant $applicant): void
    {
        if ($applicant->resume) {
            Storage::disk('public')->delete($applicant->resume);
            $applicant->resume = null;
        }
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
