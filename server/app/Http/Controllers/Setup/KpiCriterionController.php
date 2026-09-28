<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\KpiCriterionRequest;
use App\Models\KpiCriterion;
use App\Support\Hashid;
use App\Support\Setup\PerformanceFrameworkException;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → KPI & Evaluation Criteria: the weighted criteria a performance
 * evaluation scores against. Addressed by hashid; restore / force-delete take it
 * as a string. Thin controller: every write is
 * {@see PerformanceFrameworkWorkflow}, which the assistant uses too.
 */
class KpiCriterionController extends Controller
{
    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function store(KpiCriterionRequest $request): RedirectResponse
    {
        $this->workflow->saveCriterion(null, $request->validated());

        return $this->respond('KPI criterion created.');
    }

    public function update(KpiCriterionRequest $request, KpiCriterion $kpiCriterion): RedirectResponse
    {
        $this->workflow->saveCriterion($kpiCriterion, $request->validated());

        return $this->respond('KPI criterion updated.');
    }

    public function destroy(KpiCriterion $kpiCriterion): RedirectResponse
    {
        $this->workflow->archiveCriterion($kpiCriterion);

        return $this->respond('KPI criterion archived.');
    }

    public function restore(string $kpiCriterion): RedirectResponse
    {
        $this->workflow->restoreCriterion($this->findTrashed($kpiCriterion));

        return $this->respond('KPI criterion restored.');
    }

    public function forceDelete(string $kpiCriterion): RedirectResponse
    {
        try {
            $this->workflow->forceDeleteCriterion($this->findTrashed($kpiCriterion));
        } catch (PerformanceFrameworkException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
