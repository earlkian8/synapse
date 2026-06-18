<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo training program: a spread of programs across the lifecycle (past/completed,
 * ongoing, upcoming, and an open-ended e-learning course) with believable provider
 * + schedule data, and employee enrollments so the Training module has real rosters
 * and completion stats. Idempotent.
 */
class TrainingSeeder extends Seeder
{
    /**
     * The starter programs (name => attributes). Dates are relative to the demo
     * "today" (mid-June 2026) so the lifecycle statuses look natural.
     *
     * @var list<array{name: string, provider: string, start_date: ?string, end_date: ?string, capacity: ?int, description: string}>
     */
    private const PROGRAMS = [
        ['name' => 'Leadership Essentials', 'provider' => 'Dale Carnegie', 'start_date' => '2026-02-10', 'end_date' => '2026-02-12', 'capacity' => 20, 'description' => 'Core people-leadership skills for new and aspiring supervisors.'],
        ['name' => 'Advanced Excel for Analysts', 'provider' => 'Udemy Business', 'start_date' => '2026-05-04', 'end_date' => '2026-05-08', 'capacity' => 25, 'description' => 'Pivot tables, Power Query and dashboarding for data-heavy roles.'],
        ['name' => 'Occupational Safety & Health', 'provider' => 'DOLE-accredited provider', 'start_date' => '2026-06-15', 'end_date' => '2026-06-19', 'capacity' => 30, 'description' => 'Mandatory OSH orientation and workplace safety certification.'],
        ['name' => 'Customer Service Excellence', 'provider' => 'In-house', 'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'capacity' => 18, 'description' => 'Handling enquiries, complaints and service recovery with empathy.'],
        ['name' => 'Data Privacy & Cybersecurity Awareness', 'provider' => 'NPC-aligned e-learning', 'start_date' => null, 'end_date' => null, 'capacity' => null, 'description' => 'Self-paced course on the Data Privacy Act and security hygiene.'],
    ];

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

        $programs = $this->seedPrograms();

        if (TrainingEnrollment::count() > 0) {
            return;
        }

        $this->seedEnrollments($programs);
    }

    /**
     * Seed the program catalogue. Idempotent.
     *
     * @return Collection<string, TrainingProgram>
     */
    private function seedPrograms(): Collection
    {
        $programs = collect();

        foreach (self::PROGRAMS as $program) {
            $programs->put($program['name'], TrainingProgram::firstOrCreate(
                ['name' => $program['name']],
                [
                    'provider' => $program['provider'],
                    'description' => $program['description'],
                    'start_date' => $program['start_date'],
                    'end_date' => $program['end_date'],
                    'capacity' => $program['capacity'],
                ],
            ));
        }

        return $programs;
    }

    /**
     * Enroll a believable spread of employees: completed cohorts for past programs,
     * in-progress enrollments for current/upcoming ones.
     *
     * @param  Collection<string, TrainingProgram>  $programs
     */
    private function seedEnrollments(Collection $programs): void
    {
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();

        foreach ($employees as $i => $employee) {
            // Past programs: completed with a score.
            if ($i % 2 === 0) {
                $this->enroll($programs['Leadership Essentials'], $employee, 'completed', 80 + (($i * 3) % 16));
            }

            if ($i % 3 === 0) {
                $this->enroll($programs['Advanced Excel for Analysts'], $employee, 'completed', 75 + (($i * 5) % 21));
            }

            // Ongoing program: enrolled, a couple already completed.
            if ($i % 3 !== 2) {
                $this->enroll($programs['Occupational Safety & Health'], $employee, $i % 5 === 0 ? 'completed' : 'enrolled', $i % 5 === 0 ? 90.0 : null);
            }

            // Upcoming program: a small enrolled cohort.
            if ($i % 4 === 1) {
                $this->enroll($programs['Customer Service Excellence'], $employee, 'enrolled', null);
            }

            // Self-paced e-learning: broad enrollment, some completed.
            $this->enroll($programs['Data Privacy & Cybersecurity Awareness'], $employee, $i % 3 === 0 ? 'completed' : 'enrolled', $i % 3 === 0 ? 100.0 : null);
        }
    }

    private function enroll(TrainingProgram $program, Employee $employee, string $status, ?float $score): void
    {
        TrainingEnrollment::firstOrCreate(
            ['training_program_id' => $program->id, 'employee_id' => $employee->id],
            [
                'status' => $status,
                'score' => $score,
                'completed_at' => $status === 'completed' ? ($program->end_date ?? now()) : null,
            ],
        );
    }
}
