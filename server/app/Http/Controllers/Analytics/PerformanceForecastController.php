<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformanceForecastRunResource;
use App\Models\PerformanceForecastRun;
use App\Support\Ml\MlClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance Forecast — a Predictive Workforce Analytics surface. Shows the
 * latest (or a chosen historical) forecast run: every active employee ranked by a
 * model-projected next-period rating, bucketed into Below / On track / Exceeds
 * bands with a confidence and the trajectory behind each. HR triggers new runs
 * from here. Reading needs `analytics.performance.view`; running needs
 * `analytics.performance.manage`.
 */
class PerformanceForecastController extends Controller
{
    public function index(Request $request, MlClient $ml): Response
    {
        // Lightweight list of every run, for the history selector.
        $runs = PerformanceForecastRun::query()->latestFirst()->get();

        // The run being viewed: the one named in ?run=, else the newest.
        $current = $request->filled('run')
            ? $runs->firstWhere('hashid', $request->string('run')->toString())
            : $runs->first();

        if ($current) {
            $current->load([
                'generator:id,first_name,last_name',
                'targetPeriod:id,name,start_date,end_date',
                'forecasts' => fn ($query) => $query->ranked(),
                'forecasts.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'forecasts.employee.department:id,name',
                'forecasts.employee.position:id,title',
            ]);
        }

        // Liveness of the inference service, for the connectivity banner.
        $health = $ml->health();

        return Inertia::render('analytics/performance-forecast', [
            'run' => $current ? (new PerformanceForecastRunResource($current))->resolve($request) : null,
            'runs' => $runs->map(fn (PerformanceForecastRun $run): array => [
                'hashid' => $run->hashid,
                'created_at' => $run->created_at?->toIso8601String(),
                'employees_scored' => (int) $run->employees_scored,
                'exceeds_count' => (int) $run->exceeds_count,
                'average_rating' => $run->average_rating === null ? null : (float) $run->average_rating,
            ])->all(),
            'service' => [
                'connected' => $health !== null,
                'model_version' => $health['models']['performance']['version'] ?? null,
                'metrics' => $health['models']['performance']['metrics'] ?? null,
            ],
            'can' => ['manage' => $request->user()->can('analytics.performance.manage')],
        ]);
    }
}
