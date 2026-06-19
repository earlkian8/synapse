<?php

namespace Database\Factories;

use App\Models\Holiday;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->randomElement(['New Year', 'Labor Day', 'Independence Day', 'Christmas Day']),
            'date' => fake()->dateTimeThisYear()->format('Y-m-d'),
            'type' => fake()->randomElement(Holiday::TYPES),
            'is_recurring' => fake()->boolean(),
        ];
    }

    /**
     * A yearly-recurring holiday.
     */
    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => ['is_recurring' => true]);
    }
}
