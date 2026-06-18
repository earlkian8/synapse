<?php

namespace Database\Seeders;

use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo recognition program: a handful of award types (with accent colours) and a
 * believable spread of recognitions across the team and the last few months.
 * Idempotent.
 */
class AwardSeeder extends Seeder
{
    /**
     * The starter award types (name => [color, description]).
     *
     * @var array<string, array{color: string, description: string}>
     */
    private const TYPES = [
        'Employee of the Month' => ['color' => '#f59e0b', 'description' => 'Outstanding all-round contribution for the month.'],
        'Perfect Attendance' => ['color' => '#10b981', 'description' => 'No absences or tardiness for the period.'],
        'Spot Award' => ['color' => '#0ABFBF', 'description' => 'On-the-spot recognition for going above and beyond.'],
        'Innovation Award' => ['color' => '#8b5cf6', 'description' => 'A process improvement or idea that made an impact.'],
        'Years of Service' => ['color' => '#3b82f6', 'description' => 'A milestone work anniversary with the company.'],
    ];

    /**
     * A believable reason per type.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        'Employee of the Month' => 'Consistently excellent output and a great example for the team.',
        'Perfect Attendance' => 'Full attendance with zero tardiness this quarter.',
        'Spot Award' => 'Stepped up to resolve an urgent client issue over the weekend.',
        'Innovation Award' => 'Automated a manual report, saving the team hours each week.',
        'Years of Service' => 'Celebrating a milestone anniversary — thank you for your dedication.',
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

        $types = $this->seedTypes();

        if (EmployeeAward::count() > 0) {
            return;
        }

        $this->seedAwards($types);
    }

    /**
     * Seed the award-type catalogue. Idempotent.
     *
     * @return Collection<string, AwardType>
     */
    private function seedTypes(): Collection
    {
        $types = collect();

        foreach (self::TYPES as $name => $config) {
            $types->put($name, AwardType::firstOrCreate(
                ['name' => $name],
                [
                    'description' => $config['description'],
                    'color' => $config['color'],
                    'is_active' => true,
                ],
            ));
        }

        return $types;
    }

    /**
     * Give a spread of recognitions across the active team.
     *
     * @param  Collection<string, AwardType>  $types
     */
    private function seedAwards(Collection $types): void
    {
        $grantedBy = User::query()->orderBy('id')->first();
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();
        $typeNames = $types->keys()->values();

        foreach ($employees as $i => $employee) {
            // Roughly half the team has at least one recognition.
            if ($i % 2 === 1) {
                continue;
            }

            $name = $typeNames[$i % $typeNames->count()];

            EmployeeAward::create([
                'employee_id' => $employee->id,
                'award_type_id' => $types[$name]->id,
                'awarded_on' => now()->subDays(($i % 6) * 20 + 5)->toDateString(),
                'reason' => self::REASONS[$name],
                'awarded_by' => $grantedBy?->id,
            ]);

            // A second, more spontaneous recognition for a subset.
            if ($i % 4 === 0) {
                EmployeeAward::create([
                    'employee_id' => $employee->id,
                    'award_type_id' => $types['Spot Award']->id,
                    'awarded_on' => now()->subDays(($i % 3) * 10 + 2)->toDateString(),
                    'reason' => self::REASONS['Spot Award'],
                    'awarded_by' => $grantedBy?->id,
                ]);
            }
        }
    }
}
