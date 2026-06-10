<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Human Resources', 'Finance', 'Information Technology', 'Operations',
            'Sales & Marketing', 'Administration', 'Logistics', 'Legal',
        ]);

        return [
            'name' => $name,
            'code' => Str::upper(Str::substr(Str::slug($name), 0, 4)).'-'.fake()->unique()->numberBetween(10, 99),
            'parent_id' => null,
            'head_id' => null,
            'description' => fake()->optional()->sentence(),
        ];
    }
}
