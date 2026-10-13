<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Support\Admin\AdminTwoFactor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected static ?string $password;

    /**
     * An active admin who has chosen their own password and enrolled an
     * authenticator — somebody who can sign in today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Correct-Horse-9'),
            'role' => Admin::ROLE_ADMIN,
            'is_active' => true,
            'must_change_password' => false,
            'two_factor_secret' => fn (): string => app(AdminTwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => Admin::ROLE_SUPER_ADMIN]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Not enrolled yet: the next sign-in sets up the authenticator.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    }
}
