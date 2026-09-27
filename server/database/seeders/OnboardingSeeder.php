<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Support\OnboardingProvisioner;
use App\Support\Setup\SetupDefinition;
use App\Support\Setup\SetupInstaller;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class OnboardingSeeder extends Seeder
{
    /**
     * Seed a default onboarding program for the current tenant and put a few
     * existing employees through onboarding so the board isn't empty. Idempotent:
     * only seeds when no programs exist yet.
     */
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

        if (OnboardingProgram::count() > 0) {
            return;
        }

        // 1. The default program + its checklist — the one the setup wizard offers.
        $program = SetupInstaller::onboardingProgram(
            SetupDefinition::onboardingProgram(['blueprint' => 'standard']),
        );

        // 2. Start onboarding for a handful of employees and vary their progress.
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('onboardingCase')
            ->inRandomOrder()
            ->limit(5)
            ->get();

        foreach ($employees as $i => $employee) {
            $case = OnboardingProvisioner::start($employee, $program);
            $tasks = $case->tasks()->orderBy('sort_order')->get();

            if ($i === 0) {
                // A finished onboarding.
                $tasks->each->update(['status' => 'done', 'completed_at' => now()]);
                $case->update(['status' => 'completed', 'completed_at' => now()]);
            } elseif ($tasks->isNotEmpty()) {
                // Partway through.
                $tasks->take((int) ceil($tasks->count() / 2))
                    ->each->update(['status' => 'done', 'completed_at' => now()]);
                $case->update(['status' => 'in_progress']);
            }
        }
    }
}
