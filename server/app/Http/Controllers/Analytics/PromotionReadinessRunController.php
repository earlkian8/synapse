<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\PromotionReadinessRun;
use App\Support\Ml\MlException;
use App\Support\Ml\PromotionReadinessAssessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Triggers and removes promotion-readiness assessment runs. Thin: gated by
 * `analytics.promotion.manage`, it delegates the scoring to
 * {@see PromotionReadinessAssessor} and degrades gracefully when the inference
 * service is unreachable.
 */
class PromotionReadinessRunController extends Controller
{
    /**
     * Run a fresh assessment across all active employees.
     */
    public function store(Request $request, PromotionReadinessAssessor $assessor): RedirectResponse
    {
        try {
            $run = $assessor->run($request->user());
        } catch (MlException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        $declined = count($run->unassessed ?? []);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Assessment complete — {$run->employees_scored} employees scored."
                .($declined > 0 ? " {$declined} with no completed appraisal were left out." : ''),
        ]);

        return redirect()->route('analytics.promotion-readiness.index');
    }

    /**
     * Delete a historical assessment run (and its scores, via cascade).
     */
    public function destroy(PromotionReadinessRun $run, PromotionReadinessAssessor $assessor): RedirectResponse
    {
        $assessor->delete($run);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Assessment deleted.']);

        return redirect()->route('analytics.promotion-readiness.index');
    }
}
