<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'current_location' => fake()->optional()->city(),
            'headline' => fake()->jobTitle(),
            'linkedin_url' => fake()->optional(0.4)->url(),
            'portfolio_url' => fake()->optional(0.2)->url(),
            'years_experience' => fake()->optional()->numberBetween(0, 30),
            'source' => fake()->randomElement(['website', 'referral', 'linkedin', 'agency', 'walk_in', 'other']),
            'resume' => null,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
