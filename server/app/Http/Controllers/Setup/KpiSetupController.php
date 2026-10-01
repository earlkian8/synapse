<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Queries\Setup\PerformanceFrameworkScreen;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Performance framework: the four things that decide how this
 * company reviews its people — the **frameworks** appraisals are conducted
 * against, the **rating scales** they measure on, the **criteria** catalogue they
 * draw from, and the **review cycles** they run in.
 *
 * Everything is addressed by hashid; restore / force-delete take the hashid as a
 * string. See App\Http\Controllers\Performance for the module that uses them.
 */
class KpiSetupController extends Controller
{
    public function index(Request $request, PerformanceFrameworkScreen $screen): Response
    {
        return Inertia::render('setup/kpi', $screen->toArray($request));
    }
}
