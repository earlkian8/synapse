<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreProblemReportRequest;
use App\Support\ProblemReports\MyProblemReports;
use App\Support\ProblemReports\ProblemReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Problems reported to the SYNAPSE team, from the web (ADR 0096): one's own
 * reports under the Help Center, and filing one from the dialog any page opens.
 * Filing answers JSON — the dialog sits over whatever page the person was on,
 * and that page is left as it was.
 */
class ProblemReportController extends Controller
{
    public function index(Request $request): Response
    {
        $focus = $request->query('report');

        return Inertia::render('help/reports', [
            'reports' => MyProblemReports::for($request->user()),
            // The report a notification links to, opened over the list.
            'focus' => is_string($focus) ? ProblemReports::normalizeReference($focus) : null,
        ]);
    }

    public function store(StoreProblemReportRequest $request, ProblemReports $reports): JsonResponse
    {
        $report = $reports->file($request->user(), $request->report(), $request->screenshots());

        return response()->json([
            'message' => "Report {$report->reference} sent.",
            'data' => MyProblemReports::present($report),
        ], 201);
    }
}
