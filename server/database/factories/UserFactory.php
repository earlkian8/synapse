<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->lastName(),
            'last_name' => fake()->lastName(),
            'suffix' => fake()->optional(0.1)->suffix(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone_number' => fake()->optional()->phoneNumber(),
            'profile_photo' => null,
            'employee_id' => fake()->unique()->numerify('EMP-#####'),
            'is_active' => true,
            'last_login_at' => null,
            'password_changed_at' => null,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Make every factory-built identity a member of the bound tenant (or a fresh
     * organisation when none is bound), mirroring the old single-org default so
     * existing tests and seeders keep producing users that resolve a workspace.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->memberships()->exists()) {
                return;
            }

            $organization = app(Tenancy::class)->organization() ?? Organization::factory()->create();

            OrganizationProvisioner::addMember($organization, $user, default: true);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
