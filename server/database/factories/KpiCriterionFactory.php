<?php

namespace Database\Factories;

use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiCriterion>
 */
class KpiCriterionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->unique()->randomElement([
                'Quality of work', 'Productivity', 'Teamwork', 'Communication',
                'Reliability', 'Initiative', 'Job knowledge', 'Problem solving',
            ]),
            'description' => fake()->optional()->sentence(),
            'weight' => fake()->randomElement([10, 15, 20, 25, 30]),
            'rating_scale_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
