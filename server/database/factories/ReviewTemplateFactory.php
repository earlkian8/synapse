<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ReviewTemplate;
use App\Support\Performance\RatingModel;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewTemplate>
 */
class ReviewTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->unique()->randomElement([
                'Individual Contributor Review', 'People Leader Review',
                'Probationary Review', 'Annual Appraisal',
            ]),
            'description' => fake()->optional()->sentence(),
            'rating_scale_id' => null,
            'sections' => [
                ['key' => 'overall', 'name' => 'Performance criteria', 'description' => null, 'weight' => 100],
            ],
            'bands' => RatingModel::defaultBands(),
            'result_display' => 'band',
            'applies_to' => 'all',
            'applies_to_values' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * A framework whose sections carry real, differing weights — the shape the
     * two-level scoring actually has to handle.
     */
    public function sectioned(): static
    {
        return $this->state(fn (): array => [
            'sections' => [
                ['key' => 'goals', 'name' => 'Goals', 'description' => null, 'weight' => 60],
                ['key' => 'values', 'name' => 'How we work', 'description' => null, 'weight' => 40],
            ],
        ]);
    }
}
