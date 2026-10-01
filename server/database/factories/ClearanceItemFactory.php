<?php

namespace Database\Factories;

use App\Models\ClearanceItem;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClearanceItem>
 */
class ClearanceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'offboarding_case_id' => OffboardingCase::factory(),
            'item' => fake()->sentence(3),
            'department_id' => null,
            'status' => 'pending',
            'remarks' => null,
            'cleared_by' => null,
            'cleared_at' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * A signed-off clearance item.
     */
    public function cleared(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cleared',
            'cleared_at' => now(),
        ]);
    }
}
