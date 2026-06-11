<?php

namespace Database\Factories;

use App\Models\OnboardingProgram;
use App\Models\OnboardingProgramTask;
use App\Models\OnboardingTask;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingProgramTask>
 */
class OnboardingProgramTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'onboarding_program_id' => OnboardingProgram::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(OnboardingTask::CATEGORIES),
            'due_offset_days' => fake()->randomElement([1, 3, 7, 14, 30]),
            'sort_order' => 0,
        ];
    }
}
