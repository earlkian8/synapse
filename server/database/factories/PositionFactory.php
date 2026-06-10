<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Position;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $min = fake()->numberBetween(18000, 45000);

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'title' => fake()->jobTitle(),
            'department_id' => null,
            'salary_grade_min' => $min,
            'salary_grade_max' => $min + fake()->numberBetween(5000, 30000),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
