<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
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
            'recruitment_pipeline_id' => function (array $attributes) {
                $organizationId = $attributes['organization_id'];

                $pipeline = RecruitmentPipeline::withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->where('is_default', true)
                    ->first();

                return $pipeline?->id ?? RecruitmentPipeline::factory()
                    ->withStandardStages()
                    ->create(['organization_id' => $organizationId, 'is_default' => true])
                    ->id;
            },
            'title' => fake()->jobTitle(),
            'department_id' => null,
            'position_id' => null,
            'description' => fake()->paragraphs(2, true),
            'requirements' => fake()->sentences(4, true),
            'requires_resume' => true,
            'use_fit_scoring' => true,
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
