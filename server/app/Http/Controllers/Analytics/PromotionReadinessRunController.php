<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\PromotionReadinessRun;
use App\Support\ActivityLogger;
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Assessment complete — {$run->employees_scored} employees scored.",
        ]);

        return redirect()->route('analytics.promotion-readiness.index');
    }

    /**
     * Delete a historical assessment run (and its scores, via cascade).
     */
    public function destroy(PromotionReadinessRun $run): RedirectResponse
    {
        $run->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Deleted a promotion-readiness assessment run',
            logName: 'promotion-readiness',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Assessment deleted.']);

        return redirect()->route('analytics.promotion-readiness.index');
    }
}
