<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RatingScale;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingScale>
 */
class RatingScaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->unique()->randomElement([
                '5-point rating', '4-point rating', '10-point rating',
                'Competency level', 'Expectation rating',
            ]),
            'description' => fake()->optional()->sentence(),
            'type' => 'numeric',
            'min' => 1,
            'max' => 5,
            'step' => 1,
            'levels' => null,
            'is_default' => false,
        ];
    }

    /** Goal attainment on 0–100. */
    public function percentage(): static
    {
        return $this->state(fn (): array => [
            'type' => 'percentage', 'min' => 0, 'max' => 100, 'levels' => null,
        ]);
    }

    /** Named levels with behavioural anchors. */
    public function levels(): static
    {
        return $this->state(fn (): array => [
            'type' => 'levels',
            'min' => 1,
            'max' => 4,
            'levels' => [
                ['value' => 1, 'label' => 'Below', 'description' => 'Falls short of the role.'],
                ['value' => 2, 'label' => 'Meets', 'description' => 'Delivers the role.'],
                ['value' => 3, 'label' => 'Exceeds', 'description' => 'Goes past the role.'],
                ['value' => 4, 'label' => 'Role model', 'description' => 'The reference point.'],
            ],
        ]);
    }
}
