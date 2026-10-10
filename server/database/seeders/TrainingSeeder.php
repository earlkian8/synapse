<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\TrainingBudget;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use App\Support\Training\TrainingCertificates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo training program: a spread of programs across the lifecycle (past/completed,
 * ongoing, upcoming, and an open-ended e-learning course) with believable provider
 * + schedule data, and employee enrollments so the Training module has real rosters
 * and completion stats. Idempotent.
 *
 * Since ADRs 0074 / 0075 it also seeds, relative to the day it runs: programs
 * people can join (one open, one on approval, with requests waiting and one
 * declined), sessions with attendance taken at the held ones, certificates
 * (valid, expiring and expired, from programs that issue them), costs on each
 * program and this year's training budget. The mobile staff login
 * ({@see DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL}) holds a seat with sessions
 * coming up, a certificate expiring soon, and programs to join or ask to join.
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

        if (TrainingEnrollment::count() === 0) {
            $this->seedEnrollments($programs);
        }

        if (TrainingSession::count() === 0) {
            $this->seedSelfService($programs);
        }
    }

    /**
     * Sessions, attendance, certificates, requests to join, costs and a budget
     * (ADRs 0074, 0075), dated from today.
     *
     * @param  Collection<string, TrainingProgram>  $programs
     */
    private function seedSelfService(Collection $programs): void
    {
        $today = CarbonImmutable::parse(OrganizationClock::today());
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();
        $staff = $employees->firstWhere('email', DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL);
        $others = $employees->reject(fn (Employee $e): bool => $e->id === $staff?->id)->values();

        // The finished programs earn certificates — with validities that leave
        // one set expired, one expiring soon and one valid.
        $this->certify($programs['Leadership Essentials'], 6);
        $this->certify($programs['Advanced Excel for Analysts'], $this->monthsUntilSoon($programs['Advanced Excel for Analysts']));
        $this->certify($programs['Occupational Safety & Health'], 24);

        if ($staff !== null) {
            $this->enroll($programs['Advanced Excel for Analysts'], $staff, 'completed', 92.0);
            app(TrainingCertificates::class)->backfill($programs['Advanced Excel for Analysts']);
        }

        $this->sessionsFor($programs['Leadership Essentials'], '08:30', '17:00', 'Dale Carnegie Training Center, Makati', daily: true);
        $this->sessionsFor($programs['Occupational Safety & Health'], '08:00', '12:00', 'Training Room B', daily: true);

        // Running now, open to anyone: the staff login holds a seat.
        $fireSafety = TrainingProgram::firstOrCreate(['name' => 'Fire Safety & First Aid'], [
            'provider' => 'Philippine Red Cross',
            'description' => 'Basic life support, first aid and fire-drill roles, with a hands-on assessment on the last day.',
            'start_date' => $today->subDays(8)->toDateString(),
            'end_date' => $today->addDays(20)->toDateString(),
            'capacity' => 24,
            'enrollment_mode' => 'open',
            'issues_certificate' => true,
            'certificate_validity_months' => 24,
            'budget' => 85000,
        ]);

        foreach ($others->take(12) as $i => $employee) {
            $this->enroll($fireSafety, $employee, $i === 11 ? 'dropped' : 'enrolled', null);
        }

        if ($staff !== null) {
            $this->enroll($fireSafety, $staff, 'enrolled', null);
        }

        $this->sessionsFor($fireSafety, '09:00', '12:00', 'Training Room B', everyDays: 7, titles: ['Orientation and fire drill roles', 'Basic life support', 'Wounds, burns and fractures', 'Hands-on assessment']);

        // Coming up, on approval: requests waiting, one declined.
        $powerBi = TrainingProgram::firstOrCreate(['name' => 'Data Analysis with Power BI'], [
            'provider' => 'Microsoft Learn partner',
            'description' => 'From spreadsheets to dashboards: data models, DAX measures and sharing reports.',
            'start_date' => $today->addDays(20)->toDateString(),
            'end_date' => $today->addDays(24)->toDateString(),
            'capacity' => 15,
            'enrollment_mode' => 'approval',
            'issues_certificate' => true,
            'certificate_validity_months' => 36,
            'budget' => 120000,
        ]);

        foreach ($others->slice(12, 6)->values() as $i => $employee) {
            $this->enroll($powerBi, $employee, 'enrolled', null);
        }

        foreach ($others->slice(18, 3)->values() as $employee) {
            $this->enroll($powerBi, $employee, 'requested', null);
        }

        if ($declined = $others->get(21)) {
            TrainingEnrollment::firstOrCreate(
                ['training_program_id' => $powerBi->id, 'employee_id' => $declined->id],
                ['status' => 'declined', 'decided_at' => now(), 'decision_note' => 'This run is for the analytics teams; the March run is open to everyone.'],
            );
        }

        $this->sessionsFor($powerBi, '13:00', '17:00', 'Computer Lab 2', daily: true);

        // Further out, open to anyone, no certificate: the staff login can join.
        $writing = TrainingProgram::firstOrCreate(['name' => 'Effective Business Writing'], [
            'provider' => 'In-house',
            'description' => 'Clear emails, reports and proposals: structure, plain words and editing your own drafts.',
            'start_date' => $today->addDays(40)->toDateString(),
            'end_date' => $today->addDays(41)->toDateString(),
            'capacity' => 20,
            'enrollment_mode' => 'open',
            'budget' => 30000,
        ]);

        foreach ($others->slice(22, 5)->values() as $employee) {
            $this->enroll($writing, $employee, 'enrolled', null);
        }

        $this->sessionsFor($writing, '09:00', '16:00', 'Boardroom', daily: true);

        // The e-learning course stays self-paced, and anyone may ask to join it.
        $programs['Data Privacy & Cybersecurity Awareness']->update(['enrollment_mode' => 'approval']);

        $this->seedMoney($today, $programs->merge([
            $fireSafety->name => $fireSafety,
            $powerBi->name => $powerBi,
            $writing->name => $writing,
        ]));
    }

    /**
     * Months that make a certificate issued at the program's end expire within
     * the reminder window — the "expiring soon" demo.
     */
    private function monthsUntilSoon(TrainingProgram $program): int
    {
        $issued = CarbonImmutable::parse(($program->end_date ?? now())->toDateString());
        $months = 1;

        while ($issued->addMonthsNoOverflow($months)->lt(now()->addDays(20))) {
            $months++;
        }

        return $months;
    }

    private function certify(TrainingProgram $program, int $months): void
    {
        $program->update(['issues_certificate' => true, 'certificate_validity_months' => $months]);
        app(TrainingCertificates::class)->backfill($program);
    }

    /**
     * A program's sessions inside its dates — one per weekday, or one every
     * `$everyDays` days — with attendance taken at those already held.
     *
     * @param  list<string>  $titles
     */
    private function sessionsFor(TrainingProgram $program, string $start, string $end, string $where, bool $daily = false, int $everyDays = 0, array $titles = []): void
    {
        if ($program->start_date === null || $program->end_date === null) {
            return;
        }

        $day = CarbonImmutable::parse($program->start_date->toDateString());
        $last = CarbonImmutable::parse($program->end_date->toDateString());
        $n = 0;

        while ($day->lte($last)) {
            if (! $daily || ! $day->isWeekend()) {
                $session = $program->sessions()->create([
                    'title' => $titles[$n] ?? ($daily ? 'Day '.($n + 1) : null),
                    'session_date' => $day->toDateString(),
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'location' => $where,
                ]);
                $n++;

                if ($session->isHeld()) {
                    $this->takeAttendance($session);
                }
            }

            $day = $daily ? $day->addDay() : $day->addDays(max(1, $everyDays));
        }
    }

    private function takeAttendance(TrainingSession $session): void
    {
        $seated = TrainingEnrollment::query()
            ->where('training_program_id', $session->training_program_id)
            ->active()
            ->orderBy('id')
            ->get();

        foreach ($seated as $i => $enrollment) {
            $session->attendances()->create([
                'training_enrollment_id' => $enrollment->id,
                'status' => match (($i + $session->id) % 11) {
                    3 => 'late',
                    7 => 'absent',
                    9 => 'excused',
                    default => 'present',
                },
            ]);
        }
    }

    /**
     * Costs on each program, and this year's budget.
     *
     * @param  Collection<string, TrainingProgram>  $programs
     */
    private function seedMoney(CarbonImmutable $today, Collection $programs): void
    {
        $lines = [
            'Leadership Essentials' => [['trainer_fee', 'Facilitators, 3 days', 96000], ['venue', 'Training center and AV', 28000], ['meals', 'Lunch and snacks, 18 people', 16200], ['materials', 'Workbooks', 9000]],
            'Advanced Excel for Analysts' => [['trainer_fee', 'Udemy Business seats', 42000], ['certification', 'Assessment fees', 7500]],
            'Occupational Safety & Health' => [['trainer_fee', 'Accredited OSH trainer', 55000], ['materials', 'PPE kits for practicals', 18500], ['meals', 'Snacks, 5 days', 12500], ['certification', 'DOLE certificates', 6000]],
            'Customer Service Excellence' => [['trainer_fee', 'In-house facilitator allowance', 8000], ['materials', 'Role-play cards and handouts', 3500]],
            'Fire Safety & First Aid' => [['trainer_fee', 'Red Cross instructors', 48000], ['materials', 'CPR manikins rental', 15000], ['meals', 'Snacks', 6000]],
            'Data Analysis with Power BI' => [['trainer_fee', 'Deposit for the trainer', 45000]],
        ];

        foreach ($lines as $name => $costs) {
            $program = $programs->get($name);

            if ($program === null) {
                continue;
            }

            foreach ($costs as $i => [$category, $description, $amount]) {
                $incurred = CarbonImmutable::parse(($program->start_date ?? $today)->toDateString())->addDays($i * 2);

                $program->costs()->create([
                    'category' => $category,
                    'description' => $description,
                    'amount' => $amount,
                    // A cost is recorded once it is incurred, never in the future.
                    'incurred_on' => ($incurred->gt($today) ? $today->subDays(1 + $i) : $incurred)->toDateString(),
                ]);
            }
        }

        foreach (['Leadership Essentials' => 150000, 'Occupational Safety & Health' => 90000, 'Customer Service Excellence' => 15000, 'Advanced Excel for Analysts' => 50000] as $name => $budget) {
            $programs->get($name)?->update(['budget' => $budget]);
        }

        TrainingBudget::firstOrCreate(['year' => $today->year], ['amount' => 750000, 'notes' => 'Includes the compliance refreshers (OSH, fire safety) and the analytics upskilling plan.']);
        TrainingBudget::firstOrCreate(['year' => $today->year - 1], ['amount' => 600000]);
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
