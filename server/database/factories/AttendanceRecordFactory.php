<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::instance(fake()->dateTimeBetween('-2 weeks', 'now'))->startOfDay();

        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'employee_id' => Employee::factory(),
            'work_date' => $date->toDateString(),
            'work_schedule_id' => null,
            'scheduled_start' => '08:00',
            'scheduled_end' => '17:00',
            'status' => 'present',
            'first_in_at' => $date->copy()->setTime(8, 0),
            'last_out_at' => $date->copy()->setTime(17, 0),
            'worked_minutes' => 480,
            'break_minutes' => 60,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'is_manual' => false,
            'remarks' => null,
            'approval_status' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function late(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'late',
            'late_minutes' => fake()->numberBetween(5, 60),
        ]);
    }

    public function absent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'absent',
            'first_in_at' => null,
            'last_out_at' => null,
            'worked_minutes' => 0,
            'break_minutes' => 0,
        ]);
    }
}
