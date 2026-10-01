<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Queries\Setup\WorkScheduleScreen;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Work Schedule & Holidays: the work patterns employees are
 * assigned to (read by Attendance) and the organisation's holiday calendar (read
 * by Leave). Both are addressed by hashid; restore / force-delete take the hashid
 * as a string.
 *
 * A schedule is a template with a day pattern (ADR 0037), so its cycle is loaded
 * with it and the page can offer one of them as the company's default hours.
 */
class ScheduleSetupController extends Controller
{
    public function index(Request $request, WorkScheduleScreen $screen): Response
    {
        return Inertia::render('setup/schedule', $screen->toArray($request));
    }
}
