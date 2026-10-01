<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingCase>
 */
class OnboardingCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 weeks', 'now');

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'employee_id' => Employee::factory(),
            'onboarding_program_id' => null,
            'status' => 'in_progress',
            'start_date' => $start->format('Y-m-d'),
            'target_end_date' => (clone $start)->modify('+30 days')->format('Y-m-d'),
            'completed_at' => null,
            'notes' => null,
        ];
    }

    /**
     * A completed onboarding case.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
