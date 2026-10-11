<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ShowScheduleRequest;
use App\Queries\MySchedule;
use Illuminate\Http\JsonResponse;

/**
 * The signed-in employee's own schedule, a week at a time (ADR 0083). Self-
 * scoped like the rest of the app's reads: an account resolves exactly one
 * employee record, or 403 when it is not linked to one. Thin controller;
 * {@see MySchedule} builds the week.
 */
class ScheduleController extends Controller
{
    public function show(ShowScheduleRequest $request, MySchedule $schedule): JsonResponse
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return response()->json(['data' => $schedule->week($employee, $request->validated('week'))]);
    }
}
