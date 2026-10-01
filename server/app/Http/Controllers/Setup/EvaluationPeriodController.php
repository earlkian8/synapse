<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\EvaluationPeriodRequest;
use App\Models\EvaluationPeriod;
use App\Support\Hashid;
use App\Support\Setup\PerformanceFrameworkException;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → KPI & Evaluation Criteria: the review periods evaluations are
 * conducted within. Addressed by hashid; restore / force-delete take it as a
 * string. Thin controller: every write is {@see PerformanceFrameworkWorkflow},
 * which the assistant uses too.
 */
class EvaluationPeriodController extends Controller
{
    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function store(EvaluationPeriodRequest $request): RedirectResponse
    {
        $this->workflow->savePeriod(null, $request->validated());

        return $this->respond('Evaluation period created.');
    }

    public function update(EvaluationPeriodRequest $request, EvaluationPeriod $evaluationPeriod): RedirectResponse
    {
        $this->workflow->savePeriod($evaluationPeriod, $request->validated());

        return $this->respond('Evaluation period updated.');
    }

    public function destroy(EvaluationPeriod $evaluationPeriod): RedirectResponse
    {
        $this->workflow->archivePeriod($evaluationPeriod);

        return $this->respond('Evaluation period archived.');
    }

    public function restore(string $evaluationPeriod): RedirectResponse
    {
        $this->workflow->restorePeriod($this->findTrashed($evaluationPeriod));

        return $this->respond('Evaluation period restored.');
    }

    public function forceDelete(string $evaluationPeriod): RedirectResponse
    {
        try {
            $this->workflow->forceDeletePeriod($this->findTrashed($evaluationPeriod));
        } catch (PerformanceFrameworkException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
