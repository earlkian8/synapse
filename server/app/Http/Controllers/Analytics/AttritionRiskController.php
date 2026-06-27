<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttritionRiskRunResource;
use App\Models\AttritionRiskRun;
use App\Support\Ml\MlClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attrition Risk — a Predictive Workforce Analytics surface. Shows the latest (or
 * a chosen historical) assessment run: every active employee ranked by a
 * model-derived flight-risk score, bucketed into Low/Medium/High tiers with a
 * confidence and the signals behind each score. HR triggers new runs from here.
 * Reading needs `analytics.attrition.view`; running needs
 * `analytics.attrition.manage`.
 */
class AttritionRiskController extends Controller
{
    public function index(Request $request, MlClient $ml): Response
    {
        // Lightweight list of every run, for the history selector.
        $runs = AttritionRiskRun::query()->latestFirst()->get();

        // The run being viewed: the one named in ?run=, else the newest.
        $current = $request->filled('run')
            ? $runs->firstWhere('hashid', $request->string('run')->toString())
            : $runs->first();

        if ($current) {
            $current->load([
                'generator:id,first_name,last_name',
                'scores' => fn ($query) => $query->ranked(),
                'scores.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'scores.employee.department:id,name',
                'scores.employee.position:id,title',
            ]);
        }

        // Liveness of the inference service, for the connectivity banner.
        $health = $ml->health();

        return Inertia::render('analytics/attrition', [
            'run' => $current ? (new AttritionRiskRunResource($current))->resolve($request) : null,
            'runs' => $runs->map(fn (AttritionRiskRun $run): array => [
                'hashid' => $run->hashid,
                'created_at' => $run->created_at?->toIso8601String(),
                'employees_scored' => (int) $run->employees_scored,
                'high_count' => (int) $run->high_count,
                'average_score' => $run->average_score === null ? null : (float) $run->average_score,
            ])->all(),
            'service' => [
                'connected' => $health !== null,
                'model_version' => $health['models']['attrition']['version'] ?? null,
                'metrics' => $health['models']['attrition']['metrics'] ?? null,
            ],
            'can' => ['manage' => $request->user()->can('analytics.attrition.manage')],
        ]);
    }
}
