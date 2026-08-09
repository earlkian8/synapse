<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreInterviewRequest;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Support\ActivityLogger;
use App\Support\Recruitment\InterviewScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InterviewController extends Controller
{
    /**
     * Schedule an interview for an application. Advances an early-stage
     * application into the interview stage.
     */
    public function store(StoreInterviewRequest $request, JobApplication $application): RedirectResponse
    {
        InterviewScheduler::book($application, $request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Scheduled an interview for {$application->applicant->full_name}",
            subject: $application->jobPosting,
            logName: 'recruitment',
            subjectLabel: $application->applicant->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interview scheduled.']);

        return back();
    }

    /**
     * Record an interview's outcome (and optionally its feedback).
     */
    public function update(Request $request, Interview $interview): RedirectResponse
    {
        $validated = $request->validate([
            'result' => ['required', Rule::in(InterviewScheduler::RESULTS)],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $interview->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interview updated.']);

        return back();
    }

    /**
     * Remove a scheduled interview.
     */
    public function destroy(Interview $interview): RedirectResponse
    {
        $interview->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interview removed.']);

        return back();
    }
}
