<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Models\JobPosting;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class JobPostingStatusController extends Controller
{
    /**
     * Move a posting through its lifecycle (draft → open → closed / filled).
     */
    public function update(Request $request, JobPosting $jobPosting): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(StoreJobPostingRequest::STATUSES)],
        ]);

        $jobPosting->update(['status' => $validated['status']]);

        ActivityLogger::log(
            event: 'updated',
            description: "Set posting \"{$jobPosting->title}\" to {$validated['status']}",
            subject: $jobPosting,
            properties: ['status' => $validated['status']],
            logName: 'recruitment',
            subjectLabel: $jobPosting->title,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Posting status updated.']);

        return back();
    }
}
