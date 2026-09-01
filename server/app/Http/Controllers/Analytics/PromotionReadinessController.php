<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionReadinessRunResource;
use App\Models\PromotionReadinessRun;
use App\Support\Ml\MlClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promotion Readiness — a Predictive Workforce Analytics surface. Shows the
 * latest (or a chosen historical) assessment run: every active employee ranked by
 * a model-derived readiness score, bucketed into Low/Medium/High tiers with the
 * factors behind each score. HR triggers new runs from here. Reading needs
 * `analytics.promotion.view`; running needs `analytics.promotion.manage`.
 */
class PromotionReadinessController extends Controller
{
    public function index(Request $request, MlClient $ml): Response
    {
        // Lightweight list of every run, for the history selector.
        $runs = PromotionReadinessRun::query()->latestFirst()->get();

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

        return Inertia::render('analytics/promotion-readiness', [
            'run' => $current ? (new PromotionReadinessRunResource($current))->resolve($request) : null,
            'runs' => $runs->map(fn (PromotionReadinessRun $run): array => [
                'hashid' => $run->hashid,
                'created_at' => $run->created_at?->toIso8601String(),
                'employees_scored' => (int) $run->employees_scored,
                'high_count' => (int) $run->high_count,
                'average_score' => $run->average_score === null ? null : (float) $run->average_score,
            ])->all(),
            // Liveness only. The model's version and accuracy metrics stay
            // server-side: they are for whoever tunes the model, not for the HR
            // user reading this page.
            'service' => ['connected' => $health !== null],
            'can' => ['manage' => $request->user()->can('analytics.promotion.manage')],
        ]);
    }
}
