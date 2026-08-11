<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Resources\EvaluationPeriodResource;
use App\Http\Resources\KpiCriterionResource;
use App\Http\Resources\RatingScaleResource;
use App\Http\Resources\ReviewTemplateResource;
use App\Models\Department;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\Position;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Support\Performance\RatingModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Performance framework: the four things that decide how this
 * company reviews its people — the **frameworks** appraisals are conducted
 * against, the **rating scales** they measure on, the **criteria** catalogue they
 * draw from, and the **review cycles** they run in.
 *
 * Everything is addressed by hashid; restore / force-delete take the hashid as a
 * string. See App\Http\Controllers\Performance for the module that uses them.
 */
class KpiSetupController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/kpi', [
            'templates' => ReviewTemplateResource::collection($this->templates()->get())->resolve($request),
            'archivedTemplates' => ReviewTemplateResource::collection($this->templates()->onlyTrashed()->get())->resolve($request),
            'scales' => RatingScaleResource::collection($this->scales()->get())->resolve($request),
            'archivedScales' => RatingScaleResource::collection($this->scales()->onlyTrashed()->get())->resolve($request),
            'criteria' => KpiCriterionResource::collection($this->criteria()->get())->resolve($request),
            'archivedCriteria' => KpiCriterionResource::collection($this->criteria()->onlyTrashed()->get())->resolve($request),
            'periods' => EvaluationPeriodResource::collection($this->periods()->get())->resolve($request),
            'archivedPeriods' => EvaluationPeriodResource::collection($this->periods()->onlyTrashed()->get())->resolve($request),

            // What an eligibility rule can point at, and the palette a band's
            // tone is chosen from — so the editor never invents its own values.
            'audiences' => $this->audiences(),
            'tones' => RatingModel::TONES,
            'defaultBands' => RatingModel::defaultBands(),

            'can' => ['manage' => $request->user()->can('setup.kpi.manage')],
        ]);
    }

    /**
     * @return Builder<ReviewTemplate>
     */
    private function templates(): Builder
    {
        return ReviewTemplate::query()->with('items')->withCount(['items', 'evaluations'])->catalogueOrder();
    }

    /**
     * @return Builder<RatingScale>
     */
    private function scales(): Builder
    {
        return RatingScale::query()->withCount(['criteria', 'items'])->catalogueOrder();
    }

    /**
     * @return Builder<KpiCriterion>
     */
    private function criteria(): Builder
    {
        return KpiCriterion::query()->with('ratingScale')->withCount('templateItems')->catalogueOrder();
    }

    /**
     * @return Builder<EvaluationPeriod>
     */
    private function periods(): Builder
    {
        return EvaluationPeriod::query()->withCount('evaluations')->recentFirst();
    }

    /**
     * The populations a framework's eligibility rule can name. Values are strings
     * throughout, because employment type is one and ids are the others.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function audiences(): array
    {
        return [
            'department' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d): array => ['value' => (string) $d->id, 'label' => $d->name])->all(),
            'position' => Position::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn (Position $p): array => ['value' => (string) $p->id, 'label' => $p->title])->all(),
            'employment_type' => collect(StoreEmployeeRequest::EMPLOYMENT_TYPES)
                ->map(fn (string $type): array => ['value' => $type, 'label' => str($type)->replace('_', ' ')->title()->value()])
                ->all(),
        ];
    }
}
