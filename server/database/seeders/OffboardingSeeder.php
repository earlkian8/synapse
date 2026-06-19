<?php

namespace Database\Seeders;

use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Support\OffboardingProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Demo offboarding: a spread of exits across the lifecycle (just initiated, in
 * clearance, flagged, completed and cancelled) with believable clearance
 * progress, so the board, the stats and the derived clearance status all read
 * naturally. Idempotent — only seeds when no cases exist yet.
 */
class OffboardingSeeder extends Seeder
{
    /**
     * The exit kinds applied to the seeded cases, in order.
     *
     * @var list<string>
     */
    private const TYPES = ['resignation', 'end_of_contract', 'termination', 'retirement', 'resignation'];

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

        if (OffboardingCase::count() > 0) {
            return;
        }

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('offboardingCase')
            ->inRandomOrder()
            ->limit(5)
            ->get();

        foreach ($employees as $i => $employee) {
            $type = self::TYPES[$i % count(self::TYPES)];

            $case = OffboardingProvisioner::start($employee, [
                'type' => $type,
                'notice_date' => now()->subDays(20 - $i * 3)->toDateString(),
                'last_working_day' => now()->addDays(10 + $i * 4)->toDateString(),
                'reason' => $this->reasonFor($type),
            ]);

            $this->varyProgress($case, $employee, $i);
        }
    }

    /**
     * Give each seeded case a different, believable clearance state.
     */
    private function varyProgress(OffboardingCase $case, Employee $employee, int $index): void
    {
        $items = $case->clearanceItems()->orderBy('sort_order')->get();

        match ($index) {
            // A finished exit — every item cleared, employee separated.
            0 => $this->complete($case, $employee, $items),
            // Roughly half signed off.
            1 => $this->clearSome($case, $items, (int) ceil($items->count() / 2)),
            // In progress with one outstanding issue flagged.
            2 => $this->flagOne($case, $items),
            // Cancelled — the exit was withdrawn.
            4 => $case->update(['status' => 'cancelled']),
            // Index 3 stays freshly "initiated" with nothing cleared yet.
            default => null,
        };
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function complete(OffboardingCase $case, Employee $employee, $items): void
    {
        $items->each->update(['status' => 'cleared', 'cleared_at' => now()]);
        $case->update(['status' => 'completed', 'completed_at' => now()]);
        $employee->update(['employment_status' => $case->targetEmploymentStatus()]);
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function clearSome(OffboardingCase $case, $items, int $count): void
    {
        $items->take($count)->each->update(['status' => 'cleared', 'cleared_at' => now()]);
        $case->update(['status' => 'clearance']);
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function flagOne(OffboardingCase $case, $items): void
    {
        $items->take(2)->each->update(['status' => 'cleared', 'cleared_at' => now()]);

        if ($flagged = $items->get(2)) {
            $flagged->update(['status' => 'flagged', 'remarks' => 'Pending return of company laptop.']);
        }

        $case->update(['status' => 'clearance']);
    }

    private function reasonFor(string $type): string
    {
        return match ($type) {
            'termination' => 'Separation following performance review.',
            'retirement' => 'Reached retirement age after long service.',
            'end_of_contract' => 'Fixed-term contract reached its end date.',
            default => 'Accepted a new opportunity outside the company.',
        };
    }
}
