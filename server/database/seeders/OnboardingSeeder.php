<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Support\OnboardingProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class OnboardingSeeder extends Seeder
{
    /**
     * The default onboarding checklist every new hire starts with.
     *
     * @var list<array{title: string, category: string, due_offset_days: int}>
     */
    private const STANDARD_TASKS = [
        ['title' => 'Sign employment contract', 'category' => 'paperwork', 'due_offset_days' => 1],
        ['title' => 'Submit government IDs & bank details', 'category' => 'paperwork', 'due_offset_days' => 3],
        ['title' => 'Issue laptop & peripherals', 'category' => 'equipment', 'due_offset_days' => 1],
        ['title' => 'Create email & system accounts', 'category' => 'access', 'due_offset_days' => 1],
        ['title' => 'Grant building / door access', 'category' => 'access', 'due_offset_days' => 2],
        ['title' => 'Company orientation & office tour', 'category' => 'orientation', 'due_offset_days' => 2],
        ['title' => 'Meet the team & assign a buddy', 'category' => 'orientation', 'due_offset_days' => 3],
        ['title' => 'Complete code of conduct & safety training', 'category' => 'training', 'due_offset_days' => 7],
        ['title' => 'Enrol in benefits (SSS, PhilHealth, Pag-IBIG)', 'category' => 'compliance', 'due_offset_days' => 14],
        ['title' => '30-day check-in with manager', 'category' => 'other', 'due_offset_days' => 30],
    ];

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

        // 1. The default program + its checklist.
        $program = OnboardingProgram::create([
            'name' => 'Standard Onboarding',
            'description' => 'The baseline checklist every new hire goes through in their first month.',
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (self::STANDARD_TASKS as $index => $task) {
            $program->tasks()->create([
                'title' => $task['title'],
                'category' => $task['category'],
                'due_offset_days' => $task['due_offset_days'],
                'sort_order' => $index,
            ]);
        }

        // 2. Start onboarding for a handful of employees and vary their progress.
        $employees = Employee::query()
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
