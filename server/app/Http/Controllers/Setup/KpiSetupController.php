<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationPeriodResource;
use App\Http\Resources\KpiCriterionResource;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → KPI & Evaluation Criteria: the weighted criteria and the review
 * periods the Performance Management module evaluates against (see
 * App\Http\Controllers\Performance). Both are addressed by hashid; restore /
 * force-delete take the hashid as a string.
 */
class KpiSetupController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/kpi', [
            'criteria' => KpiCriterionResource::collection($this->criteria()->get())->resolve($request),
            'archivedCriteria' => KpiCriterionResource::collection($this->criteria()->onlyTrashed()->get())->resolve($request),
            'periods' => EvaluationPeriodResource::collection($this->periods()->get())->resolve($request),
            'archivedPeriods' => EvaluationPeriodResource::collection($this->periods()->onlyTrashed()->get())->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.kpi.manage')],
        ]);
    }

    /**
     * @return Builder<KpiCriterion>
     */
    private function criteria(): Builder
    {
        return KpiCriterion::query()->withCount('scores')->catalogueOrder();
    }

    /**
     * @return Builder<EvaluationPeriod>
     */
    private function periods(): Builder
    {
        return EvaluationPeriod::query()->withCount('evaluations')->recentFirst();
    }
}
