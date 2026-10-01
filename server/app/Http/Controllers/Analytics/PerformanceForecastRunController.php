<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\PerformanceForecastRun;
use App\Support\Ml\MlException;
use App\Support\Ml\PerformanceForecaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Triggers and removes performance-forecast runs. Thin: gated by
 * `analytics.performance.manage`, it delegates the scoring to
 * {@see PerformanceForecaster} and degrades gracefully when the inference service
 * is unreachable.
 */
class PerformanceForecastRunController extends Controller
{
    /**
     * Run a fresh forecast across all active employees.
     */
    public function store(Request $request, PerformanceForecaster $forecaster): RedirectResponse
    {
        try {
            $run = $forecaster->run($request->user());
        } catch (MlException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        $declined = count($run->unassessed ?? []);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Forecast complete — {$run->employees_scored} employees projected."
                .($declined > 0 ? " {$declined} with no appraisal to forecast from were left out." : ''),
        ]);

        return redirect()->route('analytics.performance-forecast.index');
    }

    /**
     * Delete a historical forecast run (and its lines, via cascade).
     */
    public function destroy(PerformanceForecastRun $run, PerformanceForecaster $forecaster): RedirectResponse
    {
        $forecaster->delete($run);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Forecast deleted.']);

        return redirect()->route('analytics.performance-forecast.index');
    }
}
