<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\JobPostingScreeningQuestion;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPostingScreeningQuestion>
 */
class JobPostingScreeningQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'job_posting_id' => JobPosting::factory(),
            'label' => fake()->randomElement([
                "Do you have a valid driver's license?",
                'Are you available for night shift?',
                'Do you have a forklift certification?',
            ]),
            'position' => 0,
        ];
    }
}
