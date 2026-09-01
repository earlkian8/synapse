<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipelineStage;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobApplication>
 */
class JobApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'job_posting_id' => JobPosting::factory(),
            'applicant_id' => Applicant::factory(),
            'rating' => fake()->optional(0.4)->numberBetween(1, 5),
            'expected_salary' => fake()->optional(0.6)->numberBetween(18000, 90000),
            'cover_note' => fake()->optional(0.4)->paragraph(),
            'rejected_reason' => null,
            'hired_employee_id' => null,
            'applied_at' => fake()->dateTimeBetween('-2 months', 'now'),
            'decided_at' => null,
        ];
    }

    /**
     * Every application needs a stage; default to its posting's pipeline entry
     * stage unless {@see stage()} named a specific one. `afterMaking` runs once
     * `job_posting_id` is already resolved on the built model, so the posting's
     * real pipeline can be looked up.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (JobApplication $application): void {
            if ($application->recruitment_pipeline_stage_id !== null) {
                return;
            }

            $posting = JobPosting::withoutGlobalScopes()->find($application->job_posting_id);
            $application->recruitment_pipeline_stage_id = $posting?->pipeline?->entryStage()?->id;
        });
    }

    /**
     * Place the application at a specific stage of its posting's pipeline,
     * matched by name (case-insensitive) — a convenience for the common case of
     * every existing test still reading `stage('screening')` etc. against the
     * standard pipeline.
     */
    public function stage(string $name): static
    {
        return $this->afterMaking(function (JobApplication $application) use ($name): void {
            $posting = JobPosting::withoutGlobalScopes()->find($application->job_posting_id);
            $stage = $posting?->pipeline?->stages->first(
                fn (RecruitmentPipelineStage $stage) => mb_strtolower($stage->name) === mb_strtolower($name),
            );

            $application->recruitment_pipeline_stage_id = $stage?->id;
        });
    }
}
