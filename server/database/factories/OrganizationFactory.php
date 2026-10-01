<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Support\Setup\CompanySetup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'legal_name' => $name.', Inc.',
            'email' => fake()->companyEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            // A factory organisation stands for a company already in use, so it is
            // past guided setup — otherwise every test signing in as its owner
            // would be redirected to the wizard (see RequireCompanySetup). Use
            // `newlyRegistered()` for the other case.
            'setup_completed_at' => now(),
        ];
    }

    /**
     * A company as registration leaves it: provisioned, empty, and still owed its
     * guided setup. See {@see CompanySetup}.
     */
    public function newlyRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'setup_completed_at' => null,
            'setup_steps' => null,
        ]);
    }
}
