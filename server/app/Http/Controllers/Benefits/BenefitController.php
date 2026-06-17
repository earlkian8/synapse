<?php

namespace App\Http\Controllers\Benefits;

use App\Http\Controllers\Controller;
use App\Http\Resources\BenefitPlanResource;
use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Benefits Administration — the live view of the organisation's benefit program:
 * an overview of every plan with its enrollment headcount and cost, and a plan's
 * roster of enrolled employees. Plans are configured in Company Setup; this module
 * manages who is enrolled. Plans are addressed by hashid.
 */
class BenefitController extends Controller
{
    /**
     * The benefits overview: cost KPIs + a card per plan.
     */
    public function index(Request $request): Response
    {
        $plans = BenefitPlan::query()
            ->withCount('enrollments')
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->active()])
            ->catalogueOrder()
            ->get();

        return Inertia::render('benefits/index', [
            'plans' => BenefitPlanResource::collection($plans)->resolve($request),
            'stats' => $this->stats($plans),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * A single plan with its enrolled employees and the employees still available
     * to enroll.
     */
    public function show(Request $request, BenefitPlan $benefitPlan): Response
    {
        $benefitPlan->loadCount('enrollments')
            ->loadCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->active()])
            ->load([
                'enrollments' => fn ($query) => $query->orderByRaw("status = 'terminated'")->latest('enrolled_on'),
                'enrollments.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'enrollments.employee.department:id,name',
                'enrollments.employee.position:id,title',
            ]);

        return Inertia::render('benefits/show', [
            'plan' => (new BenefitPlanResource($benefitPlan))->resolve($request),
            'enrollable' => $this->enrollableEmployees($benefitPlan),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Active employees not yet enrolled in this plan, for the enroll picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function enrollableEmployees(BenefitPlan $plan): array
    {
        $enrolled = $plan->enrollments()->pluck('employee_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $enrolled)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
            ->all();
    }

    /**
     * Headline benefits metrics, derived from the plan collection.
     *
     * @param  Collection<int, BenefitPlan>  $plans
     * @return array<string, int|float>
     */
    private function stats($plans): array
    {
        return [
            'plans' => $plans->where('is_active', true)->count(),
            'enrolled' => BenefitEnrollment::query()->active()->distinct()->count('employee_id'),
            'monthly_employer' => round($plans->sum(fn (BenefitPlan $p): float => $p->toMonthly((float) $p->employer_cost) * (int) $p->active_enrollments_count), 2),
            'monthly_employee' => round($plans->sum(fn (BenefitPlan $p): float => $p->toMonthly((float) $p->employee_cost) * (int) $p->active_enrollments_count), 2),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('benefits.manage')];
    }
}
