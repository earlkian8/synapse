<?php

namespace Database\Seeders;

use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo benefits program: a handful of plans (HMO, insurance, retirement, wellness)
 * with believable provider + cost figures, and a spread of employee enrollments so
 * the Benefits module has real rosters and cost rollups. Idempotent.
 */
class BenefitSeeder extends Seeder
{
    /**
     * The starter benefit plans (name => attributes).
     *
     * @var list<array{name: string, category: string, provider: string, employee_cost: float, employer_cost: float, frequency: string, description: string}>
     */
    private const PLANS = [
        ['name' => 'Maxicare HMO – Standard', 'category' => 'hmo', 'provider' => 'Maxicare', 'employee_cost' => 0, 'employer_cost' => 1500, 'frequency' => 'monthly', 'description' => 'In-patient & out-patient coverage with a nationwide hospital network.'],
        ['name' => 'Intellicare HMO – Premium', 'category' => 'hmo', 'provider' => 'Intellicare', 'employee_cost' => 800, 'employer_cost' => 2200, 'frequency' => 'monthly', 'description' => 'Upgraded room coverage and a dependent rider, employee co-pays the difference.'],
        ['name' => 'Group Life Insurance', 'category' => 'insurance', 'provider' => 'Sun Life', 'employee_cost' => 0, 'employer_cost' => 500, 'frequency' => 'monthly', 'description' => 'Company-paid group term life coverage at 24× monthly salary.'],
        ['name' => 'Personal Accident Insurance', 'category' => 'insurance', 'provider' => 'AXA', 'employee_cost' => 150, 'employer_cost' => 350, 'frequency' => 'monthly', 'description' => 'Accidental death & disablement cover, shared premium.'],
        ['name' => 'Retirement Savings Plan', 'category' => 'retirement', 'provider' => 'Company-managed', 'employee_cost' => 0, 'employer_cost' => 2000, 'frequency' => 'monthly', 'description' => 'Defined-contribution retirement fund with a company match.'],
        ['name' => 'Wellness & Fitness', 'category' => 'wellness', 'provider' => 'GymPass', 'employee_cost' => 0, 'employer_cost' => 1000, 'frequency' => 'monthly', 'description' => 'Gym, fitness studio and mental-health app access.'],
    ];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        $plans = $this->seedPlans();

        if (BenefitEnrollment::count() > 0) {
            return;
        }

        $this->seedEnrollments($plans);
    }

    /**
     * Seed the plan catalogue. Idempotent.
     *
     * @return Collection<string, BenefitPlan>
     */
    private function seedPlans()
    {
        $plans = collect();

        foreach (self::PLANS as $plan) {
            $plans->put($plan['name'], BenefitPlan::firstOrCreate(
                ['name' => $plan['name']],
                [
                    'category' => $plan['category'],
                    'provider' => $plan['provider'],
                    'description' => $plan['description'],
                    'employee_cost' => $plan['employee_cost'],
                    'employer_cost' => $plan['employer_cost'],
                    'frequency' => $plan['frequency'],
                    'is_active' => true,
                ],
            ));
        }

        return $plans;
    }

    /**
     * Enroll a believable spread of employees across the plans.
     *
     * @param  Collection<string, BenefitPlan>  $plans
     */
    private function seedEnrollments($plans): void
    {
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();

        foreach ($employees as $i => $employee) {
            $tenured = in_array($employee->employment_type, ['regular', 'probationary'], true);
            $enrolledOn = ($employee->date_hired ?? now())->toDateString();

            // Core benefits for tenured staff.
            if ($tenured) {
                $this->enroll($plans['Maxicare HMO – Standard'], $employee, 'active', $enrolledOn);
                $this->enroll($plans['Group Life Insurance'], $employee, 'active', $enrolledOn);
                $this->enroll($plans['Retirement Savings Plan'], $employee, 'active', $enrolledOn);
            }

            // A premium HMO and accident cover for a subset.
            if ($i % 3 === 0) {
                $this->enroll($plans['Intellicare HMO – Premium'], $employee, 'active', $enrolledOn);
            }

            if ($i % 4 === 0) {
                $this->enroll($plans['Personal Accident Insurance'], $employee, $tenured ? 'active' : 'pending', $enrolledOn);
            }

            if ($i % 2 === 0) {
                $this->enroll($plans['Wellness & Fitness'], $employee, 'active', $enrolledOn);
            }
        }
    }

    private function enroll(BenefitPlan $plan, Employee $employee, string $status, string $enrolledOn): void
    {
        BenefitEnrollment::firstOrCreate(
            ['benefit_plan_id' => $plan->id, 'employee_id' => $employee->id],
            ['status' => $status, 'enrolled_on' => $enrolledOn],
        );
    }
}
