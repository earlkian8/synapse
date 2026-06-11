<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Vacation Leave', 'Sick Leave', 'Emergency Leave', 'Bereavement Leave',
            'Maternity Leave', 'Paternity Leave', 'Unpaid Leave',
        ]);

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => $name,
            'code' => Str::upper(Str::substr(Str::slug($name), 0, 3)).'-'.fake()->unique()->numberBetween(10, 99),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->randomElement(['#0ABFBF', '#6366F1', '#F59E0B', '#10B981', '#EF4444', '#8B5CF6']),
            'default_days' => fake()->randomElement([5, 7, 10, 12, 15]),
            'is_paid' => true,
            'allow_half_day' => true,
            'requires_approval' => true,
            'is_active' => true,
        ];
    }

    /**
     * A type that is auto-approved on file.
     */
    public function autoApproved(): static
    {
        return $this->state(fn (array $attributes) => ['requires_approval' => false]);
    }
}
