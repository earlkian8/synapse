<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentPipeline>
 */
class RecruitmentPipelineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'name' => fake()->randomElement(['Standard Hiring', 'Warehouse Hiring', 'Executive Hiring']),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    /**
     * The classic 6-stage flow this whole feature used to hardcode — the shape
     * every pre-existing organisation was backfilled onto.
     */
    public function withStandardStages(): static
    {
        return $this->afterCreating(function (RecruitmentPipeline $pipeline): void {
            $stages = [
                ['name' => 'Applied', 'kind' => 'open'],
                ['name' => 'Screening', 'kind' => 'open'],
                ['name' => 'Interview', 'kind' => 'open'],
                ['name' => 'Offer', 'kind' => 'open'],
                ['name' => 'Hired', 'kind' => 'won'],
                ['name' => 'Rejected', 'kind' => 'lost'],
            ];

            foreach ($stages as $position => $stage) {
                RecruitmentPipelineStage::factory()->for($pipeline, 'pipeline')->create([
                    'organization_id' => $pipeline->organization_id,
                    'name' => $stage['name'],
                    'kind' => $stage['kind'],
                    'position' => $position,
                ]);
            }
        });
    }
}
