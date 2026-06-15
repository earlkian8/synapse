<?php

namespace Database\Factories;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'attendance_record_id' => AttendanceRecord::factory(),
            'employee_id' => Employee::factory(),
            'type' => fake()->randomElement(AttendancePunch::TYPES),
            'punched_at' => fake()->dateTimeBetween('-1 day', 'now'),
            'source' => 'web',
            'latitude' => null,
            'longitude' => null,
            'accuracy' => null,
            'photo' => null,
            'note' => null,
            'recorded_by' => null,
        ];
    }
}
