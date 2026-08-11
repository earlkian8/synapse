<?php

namespace Database\Factories;

use App\Models\EvaluationPeriod;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationPeriod>
 */
class EvaluationPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->unique()->randomElement(['H1', 'H2', 'Q1', 'Q2', 'Q3', 'Q4']).' '.fake()->year().' Review',
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+6 months'),
            'status' => 'open',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => 'closed']);
    }
}
