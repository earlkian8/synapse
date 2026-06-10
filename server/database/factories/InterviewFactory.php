<?php

namespace Database\Factories;

use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'job_application_id' => JobApplication::factory(),
            'interviewer_id' => null,
            'scheduled_at' => fake()->dateTimeBetween('-1 week', '+2 weeks'),
            'mode' => fake()->randomElement(['onsite', 'online', 'phone']),
            'location' => fake()->optional()->city(),
            'notes' => fake()->optional(0.3)->sentence(),
            'result' => 'pending',
            'feedback' => null,
        ];
    }
}
