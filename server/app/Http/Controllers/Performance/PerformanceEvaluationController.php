<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\StorePerformanceEvaluationRequest;
use App\Http\Requests\Performance\UpdatePerformanceEvaluationRequest;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\PerformanceEvaluation;
use App\Support\ActivityLogger;
use App\Support\Performance\PerformanceScorer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Conduct performance evaluations: open one for an employee (seeding its KPI
 * scorecard from the active criteria), save the ratings, then submit and
 * acknowledge it. Thin (route gate `performance.manage`); the overall score is
 * always derived by {@see PerformanceScorer}, never trusted from the client.
 */
class PerformanceEvaluationController extends Controller
{
    public function __construct(private readonly PerformanceScorer $scorer) {}

    /**
     * Open a new evaluation for an employee within an open period, seeded with a
     * score line per active KPI criterion.
     */
    public function store(StorePerformanceEvaluationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);

        if ($period->status !== 'open') {
            return $this->back('Evaluations can only be opened for an open period.', 'warning');
        }

        $exists = PerformanceEvaluation::query()
            ->where('employee_id', $data['employee_id'])
            ->where('evaluation_period_id', $data['evaluation_period_id'])
            ->exists();

        if ($exists) {
            return $this->back('That employee already has an evaluation for this period.', 'warning');
        }

        $criteria = KpiCriterion::query()->active()->catalogueOrder()->get();

        if ($criteria->isEmpty()) {
            return $this->back('Add at least one active KPI criterion before evaluating.', 'warning');
        }

        $employee = Employee::findOrFail($data['employee_id']);

        $evaluation = DB::transaction(function () use ($data, $criteria, $request) {
            $evaluation = PerformanceEvaluation::create([
                'employee_id' => $data['employee_id'],
                'evaluation_period_id' => $data['evaluation_period_id'],
                'evaluator_id' => $request->user()->id,
                'status' => 'draft',
            ]);

            $evaluation->scores()->createMany(
                $criteria->map(fn (KpiCriterion $criterion): array => [
                    'kpi_criterion_id' => $criterion->id,
                    'label' => $criterion->name,
                    'weight' => $criterion->weight,
                    'score' => null,
                ])->all()
            );

            return $evaluation;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Opened a performance evaluation for {$employee->full_name} ({$period->name})",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Evaluation opened.']);

        return redirect()->route('performance.show', $evaluation);
    }

    /**
     * Save the scorecard (per-criterion ratings + remarks) and overall remarks.
     * Only allowed while the evaluation is a draft; the overall score is
     * recomputed from the saved lines.
     */
    public function update(UpdatePerformanceEvaluationRequest $request, PerformanceEvaluation $evaluation): RedirectResponse
    {
        if (! $evaluation->isEditable()) {
            return $this->back('A submitted evaluation can no longer be edited.', 'warning');
        }

        $data = $request->validated();

        // Index the incoming lines by id so only this evaluation's lines apply.
        $incoming = collect($data['scores'])->keyBy('id');

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

            $evaluation->update([
                'remarks' => $data['remarks'] ?? null,
                'overall_score' => $this->scorer->overall($evaluation->scores()->get()),
            ]);
        });

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a performance evaluation scorecard',
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Scorecard saved.');
    }

    /**
     * Submit the evaluation: lock it and finalise the overall score. Every
     * criterion must be scored first.
     */
    public function submit(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if (! $evaluation->isEditable()) {
            return $this->back('This evaluation has already been submitted.', 'warning');
        }

        $scores = $evaluation->scores()->get();

        if ($scores->contains(fn ($score): bool => $score->score === null)) {
            return $this->back('Score every criterion before submitting.', 'warning');
        }

        $evaluation->update([
            'overall_score' => $this->scorer->overall($scores),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'submitted',
            description: "Submitted the performance evaluation for {$evaluation->employee?->full_name}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Evaluation submitted.');
    }

    /**
     * Acknowledge a submitted evaluation (employee sign-off, recorded by HR).
     */
    public function acknowledge(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if ($evaluation->status !== 'submitted') {
            return $this->back('Only a submitted evaluation can be acknowledged.', 'warning');
        }

        $evaluation->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'acknowledged',
            description: "Acknowledged the performance evaluation for {$evaluation->employee?->full_name}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $this->back('Evaluation acknowledged.');
    }

    /**
     * Discard a draft evaluation. Submitted / acknowledged ones are kept as a
     * record.
     */
    public function destroy(PerformanceEvaluation $evaluation): RedirectResponse
    {
        if (! $evaluation->isEditable()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'A submitted evaluation cannot be deleted.']);

            return back();
        }

        $name = $evaluation->employee?->full_name;
        $evaluation->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted a draft performance evaluation for {$name}",
            logName: 'performance',
            subjectLabel: $name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Evaluation deleted.']);

        return redirect()->route('performance.index');
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
