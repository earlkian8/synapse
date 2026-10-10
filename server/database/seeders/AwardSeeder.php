<?php

namespace Database\Seeders;

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Kudos;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\Recognition\PointsLedger;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo recognition program: a handful of award types (with accent colours) and a
 * believable spread of recognitions across the team and the last few months.
 * Idempotent.
 *
 * ADR 0071 adds the points each type carries (credited for the awards seeded),
 * a rewards catalogue, a few weeks of kudos, three nominations waiting for HR
 * and one request for a reward — written directly, so nobody is notified.
 */
class AwardSeeder extends Seeder
{
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

    /**
     * Points per type (ADR 0071), and the types the records decide, which are
     * not open to nominations.
     *
     * @var array<string, int>
     */
    private const POINTS = [
        'Employee of the Month' => 200,
        'Perfect Attendance' => 50,
        'Spot Award' => 50,
        'Innovation Award' => 150,
        'Years of Service' => 100,
    ];

    private const NOT_NOMINATED = ['Perfect Attendance', 'Years of Service'];

    /**
     * The rewards catalogue: name => [cost, stock (null = unlimited), description].
     *
     * @var array<string, array{0: int, 1: int|null, 2: string}>
     */
    private const REWARDS = [
        'Coffee voucher' => [80, 20, 'Any drink at the café downstairs.'],
        'Charity donation' => [250, null, 'We give ₱500 to the charity of your choice, in your name.'],
        'Company hoodie' => [300, 10, 'The navy one. Tell HR your size.'],
        'Half day off' => [500, null, 'Agree the date with your manager first.'],
        'Lunch with the CEO' => [800, 2, 'An hour, your questions, their treat.'],
    ];

    /** What colleagues thank each other for. */
    private const KUDOS = [
        'Thanks for covering my shift on Friday — you saved my weekend.',
        'Your onboarding notes made my first week so much easier.',
        'That client call could have gone badly. You kept it calm and clear.',
        'Fixed the printer queue nobody else would touch. Hero.',
        'Thank you for staying late to help me close the month.',
        'Your training session on the new system was the clearest I’ve had.',
        'Always the first to offer help when the team is swamped.',
        'Spotted the payroll mistake before it went out. Thank you!',
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

        if (EmployeeAward::count() === 0) {
            $this->seedAwards($types);
        }

        if (Kudos::withTrashed()->count() === 0) {
            $this->seedRecognition($types);
        }
    }

    /**
     * Points for the awards already given, a rewards catalogue, a few weeks of
     * kudos, nominations waiting for HR, and a request for a reward (ADR 0071).
     * Written directly, so seeding never notifies anybody.
     *
     * @param  Collection<string, AwardType>  $types
     */
    private function seedRecognition(Collection $types): void
    {
        $ledger = app(PointsLedger::class);
        $owner = User::query()->orderBy('id')->first();
        $points = (int) (app(Tenancy::class)->organization()?->kudos_points ?? 10);

        foreach (EmployeeAward::query()->with(['employee', 'awardType'])->get() as $award) {
            if ($award->employee && $award->awardType) {
                $ledger->post($award->employee, (int) $award->awardType->points, 'award', $award, $award->awardType->name, $owner);
            }
        }

        foreach (self::REWARDS as $name => [$cost, $stock, $description]) {
            Reward::firstOrCreate(['name' => $name], ['cost' => $cost, 'stock' => $stock, 'description' => $description]);
        }

        $people = Employee::query()->where('employment_status', 'active')->orderBy('id')->limit(24)->get()->values();

        // Kudos and nominations need a team of ten to go round.
        if ($people->count() < 10) {
            return;
        }

        foreach (self::KUDOS as $i => $message) {
            $from = $people[($i * 3) % $people->count()];
            $to = $people[($i * 3 + 5) % $people->count()];

            if ($from->is($to)) {
                continue;
            }

            $kudos = Kudos::create([
                'from_employee_id' => $from->id,
                'to_employee_id' => $to->id,
                'message' => $message,
                'points' => $points,
            ]);
            $kudos->forceFill(['created_at' => now()->subHours(6 + $i * 29), 'updated_at' => now()->subHours(6 + $i * 29)])->save();
            $ledger->post($to, $points, 'kudos', $kudos, "Kudos from {$from->full_name}", $from->user);
        }

        $reasons = [
            'Led the system migration end to end and trained every team on it.',
            'Turned around our slowest client account in under a month.',
            'Volunteered every weekend of the outreach drive and recruited others.',
        ];

        // Nominated by colleagues with their own login — never the owner, who
        // reviews them and may not review their own.
        $nominators = $people->filter(fn (Employee $employee): bool => $employee->user_id !== null && $employee->user_id !== $owner?->id)->values();

        foreach ($reasons as $i => $reason) {
            $nominator = $nominators[$i] ?? null;
            $nominee = $people[$i + 1];

            if ($nominator === null || $nominator->is($nominee)) {
                continue;
            }

            AwardNomination::create([
                'award_type_id' => $types[$i === 1 ? 'Employee of the Month' : 'Spot Award']->id,
                'employee_id' => $nominee->id,
                'nominated_by' => $nominator->user_id,
                'nominator_employee_id' => $nominator->id,
                'reason' => $reason,
            ]);
        }

        $voucher = Reward::query()->where('name', 'Coffee voucher')->first();
        $rich = $people->first(fn (Employee $employee): bool => $ledger->balance($employee) >= ($voucher?->cost ?? PHP_INT_MAX));

        if ($voucher && $rich) {
            $redemption = RewardRedemption::create(['reward_id' => $voucher->id, 'employee_id' => $rich->id, 'cost' => $voucher->cost, 'note' => 'Iced, please.']);
            $ledger->post($rich, -$voucher->cost, 'redemption', $redemption, $voucher->name, $rich->user);
            $voucher->decrement('stock');
        }
    }

    /**
     * Seed the award-type catalogue. Idempotent.
     *
     * @return Collection<string, AwardType>
     */
    private function seedTypes(): Collection
    {
        $types = collect();

        // The same starter types the setup wizard offers.
        foreach (SetupBlueprints::awardTypes() as $config) {
            $name = $config['name'];

            $types->put($name, AwardType::firstOrCreate(
                ['name' => $name],
                [
                    'description' => $config['description'],
                    'color' => $config['color'],
                    'is_active' => true,
                ],
            ));

            $types[$name]->update([
                'points' => self::POINTS[$name] ?? 0,
                'accepts_nominations' => ! in_array($name, self::NOT_NOMINATED, true),
            ]);
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
