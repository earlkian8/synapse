<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\RequestReviewsRequest;
use App\Models\AppraisalReview;
use App\Models\Employee;
use App\Models\PerformanceEvaluation;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HR's side of reviews (ADR 0072): asking the people around an appraisal for
 * their view, withdrawing a request, and reminding someone who hasn't answered.
 * Gated by `performance.manage`; the work is {@see ReviewWorkflow}.
 */
class AppraisalReviewController extends Controller
{
    use PerformanceResponses;

    public function __construct(private readonly ReviewWorkflow $workflow) {}

    /**
     * Ask some colleagues to review an appraisal. Those who can't be asked come
     * back by name with the reason, and the rest are asked anyway.
     */
    public function store(RequestReviewsRequest $request, PerformanceEvaluation $evaluation): RedirectResponse
    {
        $data = $request->validated();
        $reviewers = Employee::query()->whereIn('id', $data['reviewer_ids'])->orderBy('first_name')->get();

        try {
            $outcome = $this->workflow->request($evaluation, $reviewers, $request->user(), $data['due_on'] ?? null);
        } catch (AppraisalException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        $asked = count($outcome['requested']);
        $refused = $outcome['refused'];

        if ($asked === 0) {
            return $this->toast(implode(' ', $refused) ?: 'Nobody was asked.', 'warning');
        }

        $message = 'Asked '.$asked.' '.str('person')->plural($asked).' for a review.';

        return $this->toast($refused === [] ? $message : $message.' '.implode(' ', $refused), $refused === [] ? 'success' : 'warning');
    }

    public function cancel(Request $request, AppraisalReview $review): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->cancel($review), 'Review request withdrawn.');
    }

    public function remind(Request $request, AppraisalReview $review): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->workflow->remind($review, $request->user()),
            'Reminder sent to '.$review->reviewer?->full_name.'.',
        );
    }
}
