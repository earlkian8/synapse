<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The holidays the person keeps over the coming year (ADR 0078), as the app's
 * Holidays screen lists them: every holiday kept everywhere, and their base
 * site's local ones, each on the date it falls — Holy Week on this year's — and
 * whether it is a day off.
 */
class HolidayController extends Controller
{
    /** How far ahead the list looks. */
    private const AHEAD_DAYS = 365;

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee()->first();
        $site = HolidayCalendar::siteOf($employee);
        $from = CarbonImmutable::parse(OrganizationClock::today());
        $to = $from->addDays(self::AHEAD_DAYS);

        $holidays = Holiday::query()->keptAt($site)->with('locations:id,name')->get();
        $rows = [];

        foreach ($holidays as $holiday) {
            for ($year = $from->year; $year <= $to->year; $year++) {
                $date = $holiday->occurrenceIn($year);

                if ($date !== null && $date->betweenIncluded($from, $to)) {
                    $rows[] = [
                        'id' => $holiday->hashid.':'.$date->toDateString(),
                        'name' => $holiday->name,
                        'date' => $date->toDateString(),
                        'type' => $holiday->type,
                        'day_off' => in_array($holiday->type, Holiday::NON_WORKING_TYPES, true),
                        'repeats' => $holiday->repeatsLabel(),
                        'local' => $holiday->locations->isNotEmpty(),
                        'site' => $holiday->locations->firstWhere('id', $site)?->name,
                    ];
                }
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);

        return response()->json(['data' => $rows]);
    }
}
