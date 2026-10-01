<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingCase>
 */
class OffboardingCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $notice = fake()->dateTimeBetween('-3 weeks', 'now');

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'employee_id' => Employee::factory(),
            'type' => fake()->randomElement(OffboardingCase::TYPES),
            'notice_date' => $notice->format('Y-m-d'),
            'last_working_day' => (clone $notice)->modify('+30 days')->format('Y-m-d'),
            'reason' => fake()->sentence(),
            'status' => 'clearance',
            'completed_at' => null,
        ];
    }

    /**
     * A finalised exit.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
