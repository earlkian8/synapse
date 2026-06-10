<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
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
            'stage' => 'applied',
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
     * Place the application at a specific pipeline stage.
     */
    public function stage(string $stage): static
    {
        return $this->state(fn (array $attributes) => ['stage' => $stage]);
    }
}
