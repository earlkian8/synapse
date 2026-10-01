<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttritionRiskRunResource;
use App\Models\AttritionRiskRun;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attrition Risk — a Predictive Workforce Analytics surface. Shows the latest (or a
 * chosen historical) assessment run: every active employee ranked by a
 * model-derived flight-risk score, bucketed into Stable / At watch / High risk, with
 * the inputs and factors behind each score. HR triggers new runs from here. Reading
 * needs `analytics.attrition.view`; running needs `analytics.attrition.manage`.
 */
class AttritionRiskController extends Controller
{
    public function index(Request $request, MlClient $ml, ModelGraduation $graduation): Response
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

        // "Connected" means an assessment can actually run: the service answers AND
        // has the attrition model loaded (it serves nothing for a model that was
        // never trained on this host).
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
            // Liveness only. The model's version and accuracy metrics stay
            // server-side: they are for whoever tunes the model, not for the HR
            // user reading this page.
            'service' => ['connected' => isset($health['models']['attrition'])],
            // Model graduation (ADR 0046): where this surface stands on moving from
            // the general model to one trained on the organisation's own records.
            'graduation' => $graduation->check('attrition', isset($health['models']['attrition'])),
            'can' => ['manage' => $request->user()->can('analytics.attrition.manage')],
        ]);
    }
}
