<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the Philippine statutory holiday calendar for the current tenant: fixed
 * regular and special non-working holidays as yearly-recurring entries, plus the
 * movable National Heroes Day (last Monday of August) for the current year.
 * Idempotent — only seeds when the calendar is empty.
 */
class HolidaySeeder extends Seeder
{
    /**
     * Fixed-date **regular** holidays (recurring): [name, "MM-DD"].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const REGULAR = [
        ["New Year's Day", '01-01'],
        ['Araw ng Kagitingan', '04-09'],
        ['Labor Day', '05-01'],
        ['Independence Day', '06-12'],
        ['Bonifacio Day', '11-30'],
        ['Christmas Day', '12-25'],
        ['Rizal Day', '12-30'],
    ];

    /**
     * Fixed-date **special non-working** holidays (recurring): [name, "MM-DD"].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const SPECIAL = [
        ['EDSA People Power Anniversary', '02-25'],
        ['Ninoy Aquino Day', '08-21'],
        ["All Saints' Day", '11-01'],
        ["All Souls' Day", '11-02'],
        ['Christmas Eve', '12-24'],
        ['Last Day of the Year', '12-31'],
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

        if (Holiday::count() > 0) {
            return;
        }

        $year = now()->year;

        foreach (self::REGULAR as [$name, $monthDay]) {
            $this->seed($name, "{$year}-{$monthDay}", 'regular', true);
        }

        foreach (self::SPECIAL as [$name, $monthDay]) {
            $this->seed($name, "{$year}-{$monthDay}", 'special_non_working', true);
        }

        // National Heroes Day — the last Monday of August (movable, this year).
        $heroes = Carbon::create($year, 8, 1)->lastOfMonth(Carbon::MONDAY);
        $this->seed('National Heroes Day', $heroes->toDateString(), 'regular', false);
    }

    private function seed(string $name, string $date, string $type, bool $recurring): void
    {
        Holiday::firstOrCreate(
            ['name' => $name],
            ['date' => $date, 'type' => $type, 'is_recurring' => $recurring],
        );
    }
}
