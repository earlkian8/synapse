<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\Organization;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Seeds the Philippine statutory holiday calendar for the current tenant — the
 * same one the setup wizard offers ({@see SetupBlueprints::holidays()}): fixed
 * regular and special non-working holidays as yearly-recurring entries, and the
 * movable ones (Holy Week, National Heroes Day) on their next date. Idempotent —
 * only seeds when the calendar is empty.
 */
class HolidaySeeder extends Seeder
{
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

        if (Holiday::count() > 0) {
            return;
        }

        // The calendar the setup wizard offers, on each holiday's next date.
        foreach (SetupBlueprints::holidays(now()) as $holiday) {
            $this->seed($holiday['name'], $holiday['date'], $holiday['type'], $holiday['is_recurring']);
        }
    }

    private function seed(string $name, string $date, string $type, bool $recurring): void
    {
        Holiday::firstOrCreate(
            ['name' => $name],
            ['date' => $date, 'type' => $type, 'is_recurring' => $recurring],
        );
    }
}
