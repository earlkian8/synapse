<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\KpiCriterionRequest;
use App\Models\KpiCriterion;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → KPI & Evaluation Criteria: the weighted criteria a performance
 * evaluation scores against. Addressed by hashid; restore / force-delete take it
 * as a string. Thin controller.
 */
class KpiCriterionController extends Controller
{
    public function store(KpiCriterionRequest $request): RedirectResponse
    {
        $criterion = KpiCriterion::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created KPI criterion \"{$criterion->name}\"",
            subject: $criterion,
            logName: 'company-setup',
            subjectLabel: $criterion->name,
        );

        return $this->respond('KPI criterion created.');
    }

    public function update(KpiCriterionRequest $request, KpiCriterion $kpiCriterion): RedirectResponse
    {
        $kpiCriterion->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated KPI criterion \"{$kpiCriterion->name}\"",
            subject: $kpiCriterion,
            logName: 'company-setup',
            subjectLabel: $kpiCriterion->name,
        );

        return $this->respond('KPI criterion updated.');
    }

    public function destroy(KpiCriterion $kpiCriterion): RedirectResponse
    {
        $name = $kpiCriterion->name;
        $kpiCriterion->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived KPI criterion \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('KPI criterion archived.');
    }

    public function restore(string $kpiCriterion): RedirectResponse
    {
        $model = $this->findTrashed($kpiCriterion);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored KPI criterion \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('KPI criterion restored.');
    }

    public function forceDelete(string $kpiCriterion): RedirectResponse
    {
        $model = $this->findTrashed($kpiCriterion);

        if ($model->templateItems()->exists() || $model->scores()->exists()) {
            return $this->respond('This criterion is used by a framework or an appraisal and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted KPI criterion \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('KPI criterion permanently deleted.');
    }

    private function findTrashed(string $hashid): KpiCriterion
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return KpiCriterion::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
