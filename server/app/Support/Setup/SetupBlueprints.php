<?php

namespace App\Support\Setup;

use App\Models\OnboardingTask;
use App\Support\OffboardingProvisioner;
use App\Support\Performance\RatingScales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The starting points the setup wizard offers — the departments, kinds of leave,
 * holiday calendar, hiring processes, onboarding checklists, appraisal
 * frameworks, award types and exit clearances a company can adopt in one click
 * instead of designing from nothing on its first day.
 *
 * These are **starting points, not defaults**: nothing here is applied unless the
 * owner picks it, which keeps this codebase's "no module defaults, honest empty
 * state" convention intact (see the pipelines back-fill migration). Everything a
 * step can create is described here rather than in the client, so what the wizard
 * offers and what the server is willing to write are the same list — a posted
 * blueprint key is resolved against it, never trusted as content.
 *
 * The performance blueprints draw their instruments from
 * {@see RatingScales::library()}, so a company that starts here measures on the
 * same scales it would have built by hand.
 *
 * A company is never held to what is in here: every step of the wizard also
 * accepts the company's own definitions, and {@see SetupDefinition} is where a
 * posted key and a posted definition become the same thing. What stays
 * server-side either way is the *vocabulary with meaning attached* — a stage
 * kind, a statutory entitlement, the shape of an instrument — because those are
 * what the modules downstream read.
 */
class SetupBlueprints
{
    /**
     * The functions almost every company has. Codes match the ones the demo
     * tenant seeds, so a company that adopts these reads the same as the sample
     * data people are shown.
     *
     * @return list<array{code: string, name: string, description: string}>
     */
    public static function departments(): array
    {
        return [
            ['code' => 'HR', 'name' => 'Human Resources', 'description' => 'Hiring, records, benefits and employee relations.'],
            ['code' => 'FIN', 'name' => 'Finance', 'description' => 'Accounting, payroll, budgeting and statutory remittances.'],
            ['code' => 'OPS', 'name' => 'Operations', 'description' => 'The work the business is paid for, and the people who deliver it.'],
            ['code' => 'IT', 'name' => 'Information Technology', 'description' => 'Systems, equipment, access and support.'],
            ['code' => 'SAL', 'name' => 'Sales & Marketing', 'description' => 'Winning customers and keeping them.'],
            ['code' => 'ADM', 'name' => 'Administration', 'description' => 'Facilities, procurement and general office support.'],
        ];
    }

    /**
     * The kinds of leave a Philippine employer normally grants. The statutory
     * ones (maternity, paternity, solo parent) carry the entitlements the law
     * sets; the rest carry a common company allowance the owner can change.
     *
     * `recommended` is what the wizard pre-ticks — the set almost everyone needs
     * from day one — not a claim about what an employer is obliged to offer.
     *
     * @return list<array{code: string, name: string, description: string, color: string, default_days: float, is_paid: bool, allow_half_day: bool, requires_approval: bool, recommended: bool}>
     */
    public static function leaveTypes(): array
    {
        return [
            [
                'code' => 'VL', 'name' => 'Vacation Leave',
                'description' => 'Planned time off, filed ahead of the date.',
                'color' => '#0ABFBF', 'default_days' => 15,
                'is_paid' => true, 'allow_half_day' => true, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'SL', 'name' => 'Sick Leave',
                'description' => 'Illness or injury, filed on or after the day it happens.',
                'color' => '#F59E0B', 'default_days' => 15,
                'is_paid' => true, 'allow_half_day' => true, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'EL', 'name' => 'Emergency Leave',
                'description' => 'Urgent personal matters that cannot wait for approval.',
                'color' => '#EF4444', 'default_days' => 3,
                'is_paid' => true, 'allow_half_day' => true, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'BL', 'name' => 'Bereavement Leave',
                'description' => 'The death of an immediate family member.',
                'color' => '#64748B', 'default_days' => 3,
                'is_paid' => true, 'allow_half_day' => false, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'ML', 'name' => 'Maternity Leave',
                'description' => '105 days under RA 11210, with 15 more for a solo parent.',
                'color' => '#EC4899', 'default_days' => 105,
                'is_paid' => true, 'allow_half_day' => false, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'PL', 'name' => 'Paternity Leave',
                'description' => '7 days for a married male employee under RA 8187.',
                'color' => '#6366F1', 'default_days' => 7,
                'is_paid' => true, 'allow_half_day' => false, 'requires_approval' => true,
                'recommended' => true,
            ],
            [
                'code' => 'SPL', 'name' => 'Solo Parent Leave',
                'description' => '7 days for a qualified solo parent under RA 8972.',
                'color' => '#8B5CF6', 'default_days' => 7,
                'is_paid' => true, 'allow_half_day' => false, 'requires_approval' => true,
                'recommended' => false,
            ],
            [
                'code' => 'SIL', 'name' => 'Service Incentive Leave',
                'description' => 'The 5 days the Labor Code grants after a year of service.',
                'color' => '#10B981', 'default_days' => 5,
                'is_paid' => true, 'allow_half_day' => true, 'requires_approval' => true,
                'recommended' => false,
            ],
            [
                'code' => 'UL', 'name' => 'Unpaid Leave',
                'description' => 'Time off beyond an allowance, taken without pay.',
                'color' => '#94A3B8', 'default_days' => 0,
                'is_paid' => false, 'allow_half_day' => true, 'requires_approval' => true,
                'recommended' => false,
            ],
        ];
    }

    /**
     * Hiring processes to start from. A stage's `kind` is what recruitment keys
     * off (ADR 0029), so these differ in shape rather than only in wording.
     *
     * @return list<array{key: string, name: string, description: string, stages: list<array{name: string, kind: string}>}>
     */
    public static function pipelines(): array
    {
        return [
            [
                'key' => 'standard',
                'name' => 'Standard Hiring',
                'description' => 'Screen, interview, offer. The process most roles are hired through.',
                'stages' => [
                    ['name' => 'Applied', 'kind' => 'open'],
                    ['name' => 'Screening', 'kind' => 'open'],
                    ['name' => 'Interview', 'kind' => 'open'],
                    ['name' => 'Offer', 'kind' => 'open'],
                    ['name' => 'Hired', 'kind' => 'won'],
                    ['name' => 'Rejected', 'kind' => 'lost'],
                ],
            ],
            [
                'key' => 'fast-track',
                'name' => 'Fast Track',
                'description' => 'One conversation and a decision — for volume and frontline roles.',
                'stages' => [
                    ['name' => 'Applied', 'kind' => 'open'],
                    ['name' => 'Interview', 'kind' => 'open'],
                    ['name' => 'Hired', 'kind' => 'won'],
                    ['name' => 'Not proceeding', 'kind' => 'lost'],
                ],
            ],
            [
                'key' => 'executive',
                'name' => 'Executive Search',
                'description' => 'Sourced candidates, a panel, and a longer road to an offer.',
                'stages' => [
                    ['name' => 'Sourced', 'kind' => 'open'],
                    ['name' => 'Screening', 'kind' => 'open'],
                    ['name' => 'First interview', 'kind' => 'open'],
                    ['name' => 'Panel interview', 'kind' => 'open'],
                    ['name' => 'Final interview', 'kind' => 'open'],
                    ['name' => 'Offer', 'kind' => 'open'],
                    ['name' => 'Hired', 'kind' => 'won'],
                    ['name' => 'Rejected', 'kind' => 'lost'],
                    ['name' => 'Withdrew', 'kind' => 'lost'],
                ],
            ],
        ];
    }

    /**
     * The criteria catalogue the performance blueprints draw from — the tenant's
     * shared vocabulary for what gets measured. A framework puts a criterion in a
     * section at a weight; `weight` here is the catalogue's own default, which a
     * framework may override (see ADR 0028).
     *
     * Keyed so a framework names a criterion rather than repeating it, which is
     * also what stops two frameworks from creating two "Communication"s.
     *
     * @return array<string, array{name: string, description: string, weight: float, scale: string}>
     */
    public static function criteria(): array
    {
        return [
            'goal_attainment' => [
                'name' => 'Goal attainment',
                'description' => 'How much of what was agreed for the period was actually delivered.',
                'weight' => 30, 'scale' => 'Goal attainment (%)',
            ],
            'quality_of_work' => [
                'name' => 'Quality of work',
                'description' => 'Accuracy, thoroughness and how much rework the output needs.',
                'weight' => 20, 'scale' => '5-point rating',
            ],
            'productivity' => [
                'name' => 'Productivity',
                'description' => 'Volume of work carried, and whether it lands when it is due.',
                'weight' => 20, 'scale' => '5-point rating',
            ],
            'job_knowledge' => [
                'name' => 'Job knowledge',
                'description' => 'Command of the skills, tools and rules the role runs on.',
                'weight' => 15, 'scale' => 'Competency level',
            ],
            'problem_solving' => [
                'name' => 'Problem solving',
                'description' => 'Working out what is wrong and deciding what to do about it.',
                'weight' => 15, 'scale' => 'Competency level',
            ],
            'communication' => [
                'name' => 'Communication',
                'description' => 'Being clear and timely in writing, in person and across teams.',
                'weight' => 15, 'scale' => 'Competency level',
            ],
            'teamwork' => [
                'name' => 'Teamwork & collaboration',
                'description' => 'Working with others, and leaving them better off for it.',
                'weight' => 15, 'scale' => 'Expectation rating',
            ],
            'dependability' => [
                'name' => 'Dependability',
                'description' => 'Attendance, punctuality, and following through on commitments.',
                'weight' => 15, 'scale' => 'Expectation rating',
            ],
        ];
    }

    /**
     * Appraisal frameworks to start from. Each names the sections it divides the
     * appraisal into, and the catalogue criteria each section measures — at a
     * weight *within that section* (`weight`), which is how the framework editor
     * reads them back.
     *
     * Applying one creates only the scales and criteria it actually uses, so a
     * new tenant's catalogue is the framework it chose rather than everything
     * this class knows about.
     *
     * @return list<array{key: string, name: string, description: string, scale: string, result_display: string, sections: list<array{key: string, name: string, description: string, weight: float}>, items: list<array{criterion: string, section: string, weight: float}>}>
     */
    public static function frameworks(): array
    {
        return [
            [
                'key' => 'balanced',
                'name' => 'Balanced Appraisal',
                'description' => 'What was achieved, the skill it was achieved with, and how the person worked while doing it.',
                'scale' => '5-point rating',
                'result_display' => 'band',
                'sections' => [
                    ['key' => 'goals', 'name' => 'Goals & results', 'description' => 'What the period was supposed to produce.', 'weight' => 50],
                    ['key' => 'competencies', 'name' => 'Competencies', 'description' => 'The capability the work was done with.', 'weight' => 30],
                    ['key' => 'conduct', 'name' => 'Values & conduct', 'description' => 'How the person worked with everyone else.', 'weight' => 20],
                ],
                'items' => [
                    ['criterion' => 'goal_attainment', 'section' => 'goals', 'weight' => 60],
                    ['criterion' => 'quality_of_work', 'section' => 'goals', 'weight' => 40],
                    ['criterion' => 'job_knowledge', 'section' => 'competencies', 'weight' => 40],
                    ['criterion' => 'problem_solving', 'section' => 'competencies', 'weight' => 30],
                    ['criterion' => 'communication', 'section' => 'competencies', 'weight' => 30],
                    ['criterion' => 'teamwork', 'section' => 'conduct', 'weight' => 50],
                    ['criterion' => 'dependability', 'section' => 'conduct', 'weight' => 50],
                ],
            ],
            [
                'key' => 'competency',
                'name' => 'Competency Review',
                'description' => 'Where each person sits on the capability ladder, for companies that promote on skill.',
                'scale' => 'Competency level',
                'result_display' => 'band',
                'sections' => [
                    ['key' => 'core', 'name' => 'Core competencies', 'description' => 'What every role in the company is expected to have.', 'weight' => 60],
                    ['key' => 'delivery', 'name' => 'Delivery', 'description' => 'The work itself, at the standard of the role.', 'weight' => 40],
                ],
                'items' => [
                    ['criterion' => 'job_knowledge', 'section' => 'core', 'weight' => 35],
                    ['criterion' => 'communication', 'section' => 'core', 'weight' => 35],
                    ['criterion' => 'dependability', 'section' => 'core', 'weight' => 30],
                    ['criterion' => 'quality_of_work', 'section' => 'delivery', 'weight' => 50],
                    ['criterion' => 'productivity', 'section' => 'delivery', 'weight' => 50],
                ],
            ],
            [
                'key' => 'results',
                'name' => 'Results & Conduct',
                'description' => 'Two questions only — was it delivered, and was it delivered well. The shortest review that still says something.',
                'scale' => 'Expectation rating',
                'result_display' => 'percent',
                'sections' => [
                    ['key' => 'delivered', 'name' => 'What was delivered', 'description' => 'Against what was agreed for the period.', 'weight' => 70],
                    ['key' => 'how', 'name' => 'How it was delivered', 'description' => 'The way the person worked to get there.', 'weight' => 30],
                ],
                'items' => [
                    ['criterion' => 'goal_attainment', 'section' => 'delivered', 'weight' => 100],
                    ['criterion' => 'teamwork', 'section' => 'how', 'weight' => 50],
                    ['criterion' => 'dependability', 'section' => 'how', 'weight' => 50],
                ],
            ],
        ];
    }

    /**
     * The instruments a framework can measure on — {@see RatingScales::library()}
     * with the three-word descriptor the client shows next to each name.
     *
     * A company designing its own framework in the wizard picks from these
     * rather than defining a scale: a scale is an instrument with anchors and
     * bounds, and the screen that does it properly (Company Setup → Performance
     * framework) is one click away once setup is done.
     *
     * @return list<array{name: string, description: string, type: string, descriptor: string}>
     */
    public static function instruments(): array
    {
        return array_map(fn (array $scale): array => [
            'name' => $scale['name'],
            'description' => $scale['description'],
            'type' => $scale['type'],
            'descriptor' => RatingScales::descriptor($scale),
        ], RatingScales::library());
    }

    /**
     * The Philippine holiday calendar a company can adopt: the regular and
     * special non-working days fixed by law (RA 9492, RA 10966 and the standing
     * proclamations), each on its next occurrence from `$today`.
     *
     * A fixed-date holiday is offered as recurring, so one row honours it every
     * year. A movable one — Holy Week, which follows Easter, and National Heroes
     * Day, the last Monday of August — is offered on its next date and not as
     * recurring, because next year it falls somewhere else. The days proclaimed
     * afresh each year (Eid'l Fitr, Eid'l Adha, Chinese New Year) are not here:
     * there is no date to offer until the proclamation names one.
     *
     * @return list<array{key: string, name: string, date: string, type: string, is_recurring: bool}>
     */
    public static function holidays(CarbonInterface $today): array
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $year = $today->year;

        $fixed = [
            ['new-year', "New Year's Day", '01-01', 'regular'],
            ['edsa', 'EDSA People Power Anniversary', '02-25', 'special_non_working'],
            ['araw-ng-kagitingan', 'Araw ng Kagitingan', '04-09', 'regular'],
            ['labor-day', 'Labor Day', '05-01', 'regular'],
            ['independence-day', 'Independence Day', '06-12', 'regular'],
            ['ninoy-aquino-day', 'Ninoy Aquino Day', '08-21', 'special_non_working'],
            ['all-saints', "All Saints' Day", '11-01', 'special_non_working'],
            ['all-souls', "All Souls' Day", '11-02', 'special_non_working'],
            ['bonifacio-day', 'Bonifacio Day', '11-30', 'regular'],
            ['immaculate-conception', 'Feast of the Immaculate Conception', '12-08', 'special_non_working'],
            ['christmas-eve', 'Christmas Eve', '12-24', 'special_non_working'],
            ['christmas-day', 'Christmas Day', '12-25', 'regular'],
            ['rizal-day', 'Rizal Day', '12-30', 'regular'],
            ['last-day', 'Last Day of the Year', '12-31', 'special_non_working'],
        ];

        $holidays = array_map(fn (array $holiday): array => [
            'key' => $holiday[0],
            'name' => $holiday[1],
            'date' => "{$year}-{$holiday[2]}",
            'type' => $holiday[3],
            'is_recurring' => true,
        ], $fixed);

        // The movable ones, each on the next date it falls on.
        $movable = [
            ['maundy-thursday', 'Maundy Thursday', 'regular', fn (int $y): CarbonImmutable => self::easter($y)->subDays(3)],
            ['good-friday', 'Good Friday', 'regular', fn (int $y): CarbonImmutable => self::easter($y)->subDays(2)],
            ['black-saturday', 'Black Saturday', 'special_non_working', fn (int $y): CarbonImmutable => self::easter($y)->subDay()],
            ['national-heroes-day', 'National Heroes Day', 'regular', fn (int $y): CarbonImmutable => CarbonImmutable::create($y, 8, 1)->lastOfMonth(CarbonImmutable::MONDAY)],
        ];

        foreach ($movable as [$key, $name, $type, $on]) {
            $date = $on($year);

            if ($date->lt($today)) {
                $date = $on($year + 1);
            }

            $holidays[] = [
                'key' => $key,
                'name' => $name,
                'date' => $date->toDateString(),
                'type' => $type,
                'is_recurring' => false,
            ];
        }

        // In calendar order, as a year reads.
        usort($holidays, fn (array $a, array $b): int => substr($a['date'], 5) <=> substr($b['date'], 5));

        return $holidays;
    }

    /**
     * The recognitions most companies start with. Each is only a name, a meaning
     * and a colour — what an award is given for is decided when it is given.
     *
     * @return list<array{key: string, name: string, description: string, color: string}>
     */
    public static function awardTypes(): array
    {
        return [
            ['key' => 'employee-of-the-month', 'name' => 'Employee of the Month', 'description' => 'Outstanding all-round contribution for the month.', 'color' => '#f59e0b'],
            ['key' => 'perfect-attendance', 'name' => 'Perfect Attendance', 'description' => 'No absences or tardiness for the period.', 'color' => '#10b981'],
            ['key' => 'spot-award', 'name' => 'Spot Award', 'description' => 'On-the-spot recognition for going above and beyond.', 'color' => '#0ABFBF'],
            ['key' => 'innovation-award', 'name' => 'Innovation Award', 'description' => 'A process improvement or idea that made an impact.', 'color' => '#8b5cf6'],
            ['key' => 'years-of-service', 'name' => 'Years of Service', 'description' => 'A milestone work anniversary with the company.', 'color' => '#3b82f6'],
        ];
    }

    /**
     * Onboarding checklists to start from. A task's `category` is what the
     * onboarding board groups by ({@see OnboardingTask::CATEGORIES}); its
     * `due_offset_days` counts from the hire's start date.
     *
     * @return list<array{key: string, name: string, description: string, tasks: list<array{title: string, category: string, due_offset_days: int}>}>
     */
    public static function onboardingPrograms(): array
    {
        return [
            [
                'key' => 'standard',
                'name' => 'Standard Onboarding',
                'description' => 'The baseline checklist every new hire goes through in their first month.',
                'tasks' => [
                    ['title' => 'Sign employment contract', 'category' => 'paperwork', 'due_offset_days' => 1],
                    ['title' => 'Submit government IDs & bank details', 'category' => 'paperwork', 'due_offset_days' => 3],
                    ['title' => 'Issue laptop & peripherals', 'category' => 'equipment', 'due_offset_days' => 1],
                    ['title' => 'Create email & system accounts', 'category' => 'access', 'due_offset_days' => 1],
                    ['title' => 'Grant building / door access', 'category' => 'access', 'due_offset_days' => 2],
                    ['title' => 'Company orientation & office tour', 'category' => 'orientation', 'due_offset_days' => 2],
                    ['title' => 'Meet the team & assign a buddy', 'category' => 'orientation', 'due_offset_days' => 3],
                    ['title' => 'Complete code of conduct & safety training', 'category' => 'training', 'due_offset_days' => 7],
                    ['title' => 'Enrol in benefits (SSS, PhilHealth, Pag-IBIG)', 'category' => 'compliance', 'due_offset_days' => 14],
                    ['title' => '30-day check-in with manager', 'category' => 'other', 'due_offset_days' => 30],
                ],
            ],
        ];
    }

    /**
     * Exit clearances to start from — the provisioner's own standard list
     * ({@see OffboardingProvisioner::STANDARD_ITEMS}), so a company that adopts
     * it clears people exactly as one with no template at all would. An item is
     * routed to the department carrying its code (`__own__` is the leaver's own
     * department), which is why these codes match the suggested departments.
     *
     * @return list<array{key: string, name: string, description: string, items: list<array{item: string, department: string}>}>
     */
    public static function offboardingPrograms(): array
    {
        return [
            [
                'key' => 'standard',
                'name' => 'Standard Exit Clearance',
                'description' => 'The baseline clearance every departing employee goes through — IT, Finance, HR and their own department.',
                'items' => OffboardingProvisioner::STANDARD_ITEMS,
            ],
        ];
    }

    /**
     * Easter Sunday in the given year — the anonymous Gregorian computation, so
     * Holy Week needs neither the calendar extension nor a table of dates.
     */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * One catalogue criterion by its key, or null when nothing carries it.
     *
     * @return array{name: string, description: string, weight: float, scale: string}|null
     */
    public static function criterion(?string $key): ?array
    {
        return self::criteria()[$key] ?? null;
    }

    /**
     * One blueprint out of a list, resolved by its `key`, or null when nothing
     * carries that key. The wizard resolves every posted key through here so a
     * client can only ever choose from this file.
     *
     * @param  list<array<string, mixed>>  $blueprints
     * @return array<string, mixed>|null
     */
    public static function find(array $blueprints, ?string $key): ?array
    {
        foreach ($blueprints as $blueprint) {
            if (($blueprint['key'] ?? null) === $key) {
                return $blueprint;
            }
        }

        return null;
    }

    /**
     * The rating scale a blueprint names, out of {@see RatingScales::library()}.
     *
     * @return array<string, mixed>|null
     */
    public static function scale(string $name): ?array
    {
        foreach (RatingScales::library() as $scale) {
            if ($scale['name'] === $name) {
                return $scale;
            }
        }

        return null;
    }
}
