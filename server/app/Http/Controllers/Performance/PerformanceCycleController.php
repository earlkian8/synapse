<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\LaunchReviewCycleRequest;
use App\Models\EvaluationPeriod;
use App\Models\ReviewTemplate;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Launching a review cycle: opening the appraisals for a whole population in one
 * action. Reviewing a company one "new evaluation" click at a time is the thing
 * that makes a performance module unusable above a dozen people.
 *
 * A launch is **idempotent by design** — anyone already appraised in the cycle is
 * skipped rather than duplicated, so it can be re-run as new hires land or as
 * more departments are brought into the cycle. Each employee is seeded from the
 * framework that covers them unless one is pinned for the whole launch. The work
 * is {@see AppraisalWorkflow::launch()}, which the assistant uses too.
 */
class PerformanceCycleController extends Controller
{
    public function __construct(private readonly AppraisalWorkflow $workflow) {}

    public function store(LaunchReviewCycleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);
        $pinned = isset($data['review_template_id'])
            ? ReviewTemplate::findOrFail($data['review_template_id'])
            : null;
        $departments = $data['scope'] === 'departments'
            ? array_map('intval', $data['department_ids'] ?? [])
            : null;

        try {
            $launch = $this->workflow->launch($period, $departments, $pinned, $request->user());
        } catch (AppraisalException $e) {
            return $this->back($e->getMessage(), 'warning');
        }

        return $this->back(...$launch->message());
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
