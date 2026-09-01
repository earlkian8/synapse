<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentPipelineStage>
 */
class RecruitmentPipelineStageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'recruitment_pipeline_id' => RecruitmentPipeline::factory(),
            'name' => fake()->randomElement(['Applied', 'Screening', 'Interview', 'Offer']),
            'kind' => 'open',
            'position' => 0,
        ];
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes) => ['name' => 'Hired', 'kind' => 'won']);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => ['name' => 'Rejected', 'kind' => 'lost']);
    }
}
