<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AttritionRiskRun;
use App\Support\Ml\AttritionRiskAssessor;
use App\Support\Ml\MlException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Triggers and removes attrition-risk assessment runs. Thin: gated by
 * `analytics.attrition.manage`, it delegates the scoring to
 * {@see AttritionRiskAssessor} and degrades gracefully when the inference service
 * is unreachable.
 */
class AttritionRiskRunController extends Controller
{
    /**
     * Run a fresh assessment across all active employees.
     */
    public function store(Request $request, AttritionRiskAssessor $assessor): RedirectResponse
    {
        try {
            $run = $assessor->run($request->user());
        } catch (MlException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Assessment complete — {$run->employees_scored} employees scored.",
        ]);

        return redirect()->route('analytics.attrition.index');
    }

    /**
     * Delete a historical assessment run (and its scores, via cascade).
     */
    public function destroy(AttritionRiskRun $run, AttritionRiskAssessor $assessor): RedirectResponse
    {
        $assessor->delete($run);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Assessment deleted.']);

        return redirect()->route('analytics.attrition.index');
    }
}
