<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\StorePerformanceEvaluationRequest;
use App\Http\Requests\Performance\UpdatePerformanceEvaluationRequest;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\ReviewTemplate;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\PerformanceScorer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Conduct performance appraisals: open one against an appraisal framework, save
 * the ratings, then submit and acknowledge it. Thin (route gate
 * `performance.manage`): every change goes through {@see AppraisalWorkflow} —
 * the same path the assistant takes — so the scorecard is always seeded by
 * {@see EvaluationOpener} and the result always derived by
 * {@see PerformanceScorer}, never trusted from the client.
 */
class PerformanceEvaluationController extends Controller
{
    public function __construct(private readonly AppraisalWorkflow $workflow) {}

    /**
     * Open a new appraisal for an employee within an open cycle, seeded from the
     * framework chosen for it — or, when none is named, from the one that covers
     * the employee.
     */
    public function store(StorePerformanceEvaluationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);
        $employee = Employee::findOrFail($data['employee_id']);
        $template = isset($data['review_template_id'])
            ? ReviewTemplate::findOrFail($data['review_template_id'])
            : null;

        try {
            $evaluation = $this->workflow->open($employee, $period, $template, $request->user());
        } catch (AppraisalException $e) {
            return $this->back($e->getMessage(), 'warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Appraisal opened.']);

        return redirect()->route('performance.show', $evaluation);
    }

    /**
     * Save the scorecard (per-criterion ratings + remarks) and overall remarks.
     * Only allowed while the appraisal is a draft; the result is recomputed from
     * the saved lines against the framework's rating model.
     */
    public function update(UpdatePerformanceEvaluationRequest $request, PerformanceEvaluation $evaluation): RedirectResponse
    {
        $data = $request->validated();

        // Index the incoming lines by id so only this appraisal's lines apply.
        $lines = collect($data['scores'])
            ->keyBy('id')
            ->map(fn (array $line): array => [
                'score' => $line['score'] ?? null,
                'remarks' => $line['remarks'] ?? null,
            ])
            ->all();

        try {
            $this->workflow->rate($evaluation, $lines, $data['remarks'] ?? null, setRemarks: true);
        } catch (AppraisalException $e) {
            return $this->back($e->getMessage(), 'warning');
        }

        return $this->back('Scorecard saved.');
    }

    /**
     * Submit the appraisal: lock it and finalise the result. Every line must be
     * rated first.
     */
    public function submit(PerformanceEvaluation $evaluation): RedirectResponse
    {
        try {
            $this->workflow->submit($evaluation);
        } catch (AppraisalException $e) {
            return $this->back($e->getMessage(), 'warning');
        }

        return $this->back('Appraisal submitted.');
    }

    /**
     * Acknowledge a submitted appraisal (employee sign-off, recorded by HR).
     */
    public function acknowledge(PerformanceEvaluation $evaluation): RedirectResponse
    {
        try {
            $this->workflow->acknowledge($evaluation);
        } catch (AppraisalException $e) {
            return $this->back($e->getMessage(), 'warning');
        }

        return $this->back('Appraisal acknowledged.');
    }

    /**
     * Discard a draft appraisal. Submitted / acknowledged ones are kept as a
     * record.
     */
    public function destroy(PerformanceEvaluation $evaluation): RedirectResponse
    {
        try {
            $this->workflow->discard($evaluation);
        } catch (AppraisalException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft deleted.']);

        return redirect()->route('performance.index');
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
