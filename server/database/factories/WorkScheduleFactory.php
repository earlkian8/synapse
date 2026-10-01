<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WorkSchedule;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->randomElement(['Day Shift', 'Mid Shift', 'Night Shift', 'Flexible']),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'grace_minutes' => 15,
            'required_hours' => 8,
        ];
    }
}
