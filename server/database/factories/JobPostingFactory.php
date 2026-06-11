<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'title' => fake()->jobTitle(),
            'department_id' => null,
            'position_id' => null,
            'description' => fake()->paragraphs(2, true),
            'requirements' => fake()->sentences(4, true),
            'employment_type' => fake()->randomElement(['regular', 'probationary', 'contractual', 'part_time']),
            'openings' => fake()->numberBetween(1, 4),
            'status' => 'open',
            'closing_date' => fake()->optional(0.7)->dateTimeBetween('+1 week', '+2 months')?->format('Y-m-d'),
            'posted_by' => null,
        ];
    }

    /**
     * Indicate the posting is still a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }
}
