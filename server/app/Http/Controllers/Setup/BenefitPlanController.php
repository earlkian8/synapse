<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\BenefitPlanRequest;
use App\Http\Resources\BenefitPlanResource;
use App\Models\BenefitPlan;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Benefits: the catalogue of benefit plans (HMO, insurance,
 * retirement, wellness…) the Benefits module enrolls employees into. Addressed by
 * hashid; restore / force-delete take it as a string. Thin controller.
 */
class BenefitPlanController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/benefits', [
            'plans' => BenefitPlanResource::collection($this->listing()->get())->resolve($request),
            'archived' => BenefitPlanResource::collection($this->listing()->onlyTrashed()->get())->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.benefits.manage')],
        ]);
    }

    public function store(BenefitPlanRequest $request): RedirectResponse
    {
        $plan = BenefitPlan::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created benefit plan \"{$plan->name}\"",
            subject: $plan,
            logName: 'company-setup',
            subjectLabel: $plan->name,
        );

        return $this->respond('Benefit plan created.');
    }

    public function update(BenefitPlanRequest $request, BenefitPlan $benefitPlan): RedirectResponse
    {
        $benefitPlan->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated benefit plan \"{$benefitPlan->name}\"",
            subject: $benefitPlan,
            logName: 'company-setup',
            subjectLabel: $benefitPlan->name,
        );

        return $this->respond('Benefit plan updated.');
    }

    public function destroy(BenefitPlan $benefitPlan): RedirectResponse
    {
        $name = $benefitPlan->name;
        $benefitPlan->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived benefit plan \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Benefit plan archived.');
    }

    public function restore(string $benefitPlan): RedirectResponse
    {
        $model = $this->findTrashed($benefitPlan);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored benefit plan \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Benefit plan restored.');
    }

    public function forceDelete(string $benefitPlan): RedirectResponse
    {
        $model = $this->findTrashed($benefitPlan);

        if ($model->enrollments()->exists()) {
            return $this->respond('This plan has enrollments and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted benefit plan \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Benefit plan permanently deleted.');
    }

    /**
     * The base listing query, shared by the active and archived sets.
     *
     * @return Builder<BenefitPlan>
     */
    private function listing(): Builder
    {
        return BenefitPlan::query()->withCount('enrollments')->catalogueOrder();
    }

    private function findTrashed(string $hashid): BenefitPlan
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return BenefitPlan::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
