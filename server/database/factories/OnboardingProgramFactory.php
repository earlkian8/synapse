<?php

namespace Database\Factories;

use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingProgram>
 */
class OnboardingProgramFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->randomElement(['Standard Onboarding', 'Engineering Onboarding', 'Field Staff Onboarding']),
            'description' => fake()->optional()->sentence(),
            'department_id' => null,
            'employment_type' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Mark this program as the tenant default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
