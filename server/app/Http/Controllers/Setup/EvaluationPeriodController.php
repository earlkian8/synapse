<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\EvaluationPeriodRequest;
use App\Models\EvaluationPeriod;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → KPI & Evaluation Criteria: the review periods evaluations are
 * conducted within. Addressed by hashid; restore / force-delete take it as a
 * string. Thin controller.
 */
class EvaluationPeriodController extends Controller
{
    public function store(EvaluationPeriodRequest $request): RedirectResponse
    {
        $period = EvaluationPeriod::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created evaluation period \"{$period->name}\"",
            subject: $period,
            logName: 'company-setup',
            subjectLabel: $period->name,
        );

        return $this->respond('Evaluation period created.');
    }

    public function update(EvaluationPeriodRequest $request, EvaluationPeriod $evaluationPeriod): RedirectResponse
    {
        $evaluationPeriod->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated evaluation period \"{$evaluationPeriod->name}\"",
            subject: $evaluationPeriod,
            logName: 'company-setup',
            subjectLabel: $evaluationPeriod->name,
        );

        return $this->respond('Evaluation period updated.');
    }

    public function destroy(EvaluationPeriod $evaluationPeriod): RedirectResponse
    {
        $name = $evaluationPeriod->name;
        $evaluationPeriod->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived evaluation period \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Evaluation period archived.');
    }

    public function restore(string $evaluationPeriod): RedirectResponse
    {
        $model = $this->findTrashed($evaluationPeriod);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored evaluation period \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Evaluation period restored.');
    }

    public function forceDelete(string $evaluationPeriod): RedirectResponse
    {
        $model = $this->findTrashed($evaluationPeriod);

        if ($model->evaluations()->exists()) {
            return $this->respond('This period has evaluations and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted evaluation period \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Evaluation period permanently deleted.');
    }

    private function findTrashed(string $hashid): EvaluationPeriod
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return EvaluationPeriod::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
