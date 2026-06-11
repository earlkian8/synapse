<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\WorkSchedule;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    /**
     * Seed the organisation foundation (departments, positions, schedules) and a
     * starter set of employees for the current tenant. Idempotent: safe to re-run.
     */
    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        // 1. Departments.
        $departments = collect([
            ['name' => 'Human Resources', 'code' => 'HR'],
            ['name' => 'Information Technology', 'code' => 'IT'],
            ['name' => 'Finance', 'code' => 'FIN'],
            ['name' => 'Operations', 'code' => 'OPS'],
            ['name' => 'Sales & Marketing', 'code' => 'SAL'],
        ])->map(fn (array $d) => Department::firstOrCreate(['code' => $d['code']], ['name' => $d['name']]));

        // 2. Positions per department.
        $positionsByDept = [
            'HR' => ['HR Manager', 'HR Officer', 'Recruiter'],
            'IT' => ['IT Manager', 'Software Engineer', 'Systems Administrator'],
            'FIN' => ['Finance Manager', 'Accountant', 'Payroll Officer'],
            'OPS' => ['Operations Manager', 'Operations Associate'],
            'SAL' => ['Sales Manager', 'Account Executive', 'Marketing Specialist'],
        ];

        foreach ($departments as $department) {
            foreach ($positionsByDept[$department->code] ?? [] as $title) {
                Position::firstOrCreate(
                    ['title' => $title, 'department_id' => $department->id],
                    ['salary_grade_min' => 20000, 'salary_grade_max' => 80000],
                );
            }
        }

        // 3. Work schedules.
        $day = WorkSchedule::firstOrCreate(['name' => 'Day Shift'], [
            'start_time' => '08:00',
            'end_time' => '17:00',
            'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'grace_minutes' => 15,
            'required_hours' => 8,
        ]);

        WorkSchedule::firstOrCreate(['name' => 'Night Shift'], [
            'start_time' => '22:00',
            'end_time' => '06:00',
            'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'grace_minutes' => 15,
            'required_hours' => 8,
        ]);

        // 4. A starter set of employees, only if none exist yet.
        if (Employee::count() === 0) {
            $hr = $departments->firstWhere('code', 'HR');
            $hrPosition = Position::where('title', 'HR Manager')->first();

            Employee::factory()->regular()->create([
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'department_id' => $hr?->id,
                'position_id' => $hrPosition?->id,
                'work_schedule_id' => $day->id,
            ]);

            Employee::factory()
                ->count(24)
                ->create()
                ->each(function (Employee $employee) use ($departments) {
                    $department = $departments->random();
                    $employee->update([
                        'department_id' => $department->id,
                        'position_id' => Position::where('department_id', $department->id)
                            ->inRandomOrder()->value('id'),
                        'work_schedule_id' => WorkSchedule::inRandomOrder()->value('id'),
                    ]);
                });
        }

        // 5. A bit of structure for the demo (idempotent): nested sub-departments
        // and a head for each top-level department.
        $it = $departments->firstWhere('code', 'IT');
        $hr = $departments->firstWhere('code', 'HR');

        foreach ([['IT Support', 'ITS', $it], ['Recruitment', 'REC', $hr]] as [$name, $code, $parent]) {
            if ($parent) {
                Department::firstOrCreate(['code' => $code], ['name' => $name, 'parent_id' => $parent->id]);
            }
        }

        $departments->each(function (Department $department) {
            if ($department->head_id === null) {
                $headId = Employee::where('department_id', $department->id)->value('id');

                if ($headId) {
                    $department->update(['head_id' => $headId]);
                }
            }
        });

        // 6. Give every employee a demo profile portrait (idempotent: only fills
        // in the ones still missing one). Seeded photos are absolute URLs, which
        // the Employee::photoUrl() accessor passes through unchanged.
        Employee::whereNull('photo')->get()->each(
            fn (Employee $employee) => $employee->update(['photo' => $this->portrait($employee)]),
        );
    }

    /**
     * A stable, gender-matched demo portrait URL for an employee.
     */
    private function portrait(Employee $employee): string
    {
        $bucket = $employee->gender === 'female' ? 'women' : 'men';
        $n = $employee->id % 100;

        return "https://randomuser.me/api/portraits/{$bucket}/{$n}.jpg";
    }
}
