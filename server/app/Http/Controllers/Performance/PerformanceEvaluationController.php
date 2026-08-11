<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\StorePerformanceEvaluationRequest;
use App\Http\Requests\Performance\UpdatePerformanceEvaluationRequest;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\ReviewTemplate;
use App\Support\ActivityLogger;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\TemplateResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Conduct performance appraisals: open one against an appraisal framework, save
 * the ratings, then submit and acknowledge it. Thin (route gate
 * `performance.manage`); the scorecard is always seeded by
 * {@see EvaluationOpener} and the result always derived by
 * {@see PerformanceScorer}, never trusted from the client.
 */
class PerformanceEvaluationController extends Controller
{
    public function __construct(
        private readonly PerformanceScorer $scorer,
        private readonly EvaluationOpener $opener,
        private readonly TemplateResolver $templates,
    ) {}

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

        if ($reason = $this->opener->blockedReason($employee, $period)) {
            return $this->back($reason, 'warning');
        }

        $template = isset($data['review_template_id'])
            ? ReviewTemplate::findOrFail($data['review_template_id'])
            : $this->templates->forEmployee($employee);

        if ($template === null) {
            return $this->back('No appraisal framework covers this employee. Set one up under Company Setup.', 'warning');
        }

        if ($template->items()->count() === 0) {
            return $this->back("“{$template->name}” has nothing to measure yet. Add its criteria first.", 'warning');
        }

        $evaluation = $this->opener->open($employee, $period, $template, $request->user());

        ActivityLogger::log(
            event: 'created',
            description: "Opened a {$template->name} appraisal for {$employee->full_name} ({$period->name})",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $employee->full_name,
        );

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
        if (! $evaluation->isEditable()) {
            return $this->back('A submitted appraisal can no longer be edited.', 'warning');
        }

        $data = $request->validated();

        // Index the incoming lines by id so only this appraisal's lines apply.
        $incoming = collect($data['scores'])->keyBy('id');

        // Guard: a rating must be a value its own line's snapshot scale can take.
        // The UI constrains this, but the bounds are never taken from the client.
        foreach ($evaluation->scores as $score) {
            $value = $incoming->get($score->id)['score'] ?? null;

            if ($value !== null && ! $score->acceptsScore((float) $value)) {
                return $this->back('A rating is outside its criterion’s scale.', 'warning');
            }
        }

        DB::transaction(function () use ($evaluation, $incoming, $data) {
            foreach ($evaluation->scores as $score) {
                $line = $incoming->get($score->id);

                if ($line === null) {
                    continue;
                }

                $score->update([
                    'score' => $line['score'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }

            $evaluation->applyResult($this->scorer->score($evaluation->scores()->get(), $evaluation->bandList()));
            $evaluation->remarks = $data['remarks'] ?? null;
            $evaluation->save();
        });

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a performance scorecard',
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Scorecard saved.');
    }

    /**
     * Submit the appraisal: lock it and finalise the result. Every line must be
     * rated first.
     */
    public function submit(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if (! $evaluation->isEditable()) {
            return $this->back('This appraisal has already been submitted.', 'warning');
        }

        $result = $this->scorer->score($evaluation->scores()->get(), $evaluation->bandList());

        if (! $result->isComplete()) {
            return $this->back('Rate every criterion before submitting.', 'warning');
        }

        $evaluation->applyResult($result);
        $evaluation->status = 'submitted';
        $evaluation->submitted_at = now();
        $evaluation->save();

        ActivityLogger::log(
            event: 'submitted',
            description: "Submitted the appraisal for {$evaluation->employee?->full_name}"
                .($result->band ? " — {$result->band['label']}" : ''),
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Appraisal submitted.');
    }

    /**
     * Acknowledge a submitted appraisal (employee sign-off, recorded by HR).
     */
    public function acknowledge(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if ($evaluation->status !== 'submitted') {
            return $this->back('Only a submitted appraisal can be acknowledged.', 'warning');
        }

        $evaluation->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'acknowledged',
            description: "Acknowledged the appraisal for {$evaluation->employee?->full_name}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Appraisal acknowledged.');
    }

    /**
     * Discard a draft appraisal. Submitted / acknowledged ones are kept as a
     * record.
     */
    public function destroy(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if (! $evaluation->isEditable()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'A submitted appraisal cannot be deleted.']);

            return back();
        }

        $name = $evaluation->employee?->full_name;
        $evaluation->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted a draft appraisal for {$name}",
            logName: 'performance',
            subjectLabel: $name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft deleted.']);

        return redirect()->route('performance.index');
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
