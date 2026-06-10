<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hired = fake()->dateTimeBetween('-6 years', '-2 months');

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'user_id' => null,
            'employee_no' => fake()->unique()->numerify('EMP-#####'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->lastName(),
            'last_name' => fake()->lastName(),
            'suffix' => fake()->optional(0.1)->suffix(),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-21 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'civil_status' => fake()->randomElement(['single', 'married', 'widowed', 'separated']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'photo' => null,
            'department_id' => null,
            'position_id' => null,
            'manager_id' => null,
            'work_schedule_id' => null,
            'employment_type' => fake()->randomElement(['regular', 'probationary', 'contractual', 'part_time']),
            'employment_status' => 'active',
            'date_hired' => $hired->format('Y-m-d'),
            'date_regularized' => fake()->optional(0.7)->dateTimeBetween($hired, 'now')?->format('Y-m-d'),
            'basic_salary' => fake()->numberBetween(18000, 90000),
            'bank_name' => fake()->optional()->randomElement(['BDO', 'BPI', 'Metrobank', 'Landbank']),
            'bank_account_no' => fake()->optional()->bankAccountNumber(),
            'tin' => fake()->optional()->numerify('###-###-###-000'),
            'sss_no' => fake()->optional()->numerify('##-#######-#'),
            'philhealth_no' => fake()->optional()->numerify('##-#########-#'),
            'pagibig_no' => fake()->optional()->numerify('####-####-####'),
        ];
    }

    /**
     * Indicate the employee is a regular (regularized) hire.
     */
    public function regular(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_type' => 'regular',
            'date_regularized' => now()->subYear()->format('Y-m-d'),
        ]);
    }
}
