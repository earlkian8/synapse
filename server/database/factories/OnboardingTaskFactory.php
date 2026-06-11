<?php

namespace Database\Factories;

use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingTask>
 */
class OnboardingTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'onboarding_case_id' => OnboardingCase::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(OnboardingTask::CATEGORIES),
            'assigned_to' => null,
            'due_date' => fake()->dateTimeBetween('now', '+3 weeks')->format('Y-m-d'),
            'status' => 'pending',
            'completed_at' => null,
            'completed_by' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * A completed task.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
            'completed_at' => now(),
        ]);
    }
}
