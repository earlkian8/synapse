<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreProblemReportRequest;
use App\Support\ProblemReports\MyProblemReports;
use App\Support\ProblemReports\ProblemReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Problems reported to the SYNAPSE team, from the phone (ADR 0096): the same
 * filing and the same reading as the web's ({@see MyProblemReports}), and only
 * one's own.
 */
class ProblemReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => MyProblemReports::for($request->user())]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $report = MyProblemReports::find($request->user(), $reference);

        abort_if($report === null, 404);

        return response()->json(['data' => MyProblemReports::present($report)]);
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
