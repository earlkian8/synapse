<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProblemReportRequest;
use App\Http\Resources\Admin\ProblemReportResource;
use App\Models\ProblemReport;
use App\Models\ProblemReportAttachment;
use App\Queries\Admin\ProblemReportsIndexQuery;
use App\Support\Files\FileStream;
use App\Support\ProblemReports\ProblemReportTriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Problem reports from every organisation (ADR 0096), for every admin: the
 * list, one report, following it up ({@see ProblemReportTriage}), and its
 * screenshots, streamed to the admin's token.
 */
class ProblemReportController extends Controller
{
    public function index(Request $request, ProblemReportsIndexQuery $query): JsonResponse
    {
        return ProblemReportResource::collection($query->paginate($request))
            ->additional([
                'stats' => $query->stats(),
                'filters' => $query->allFilters($request),
            ])
            ->response();
    }

    /** What the sidebar badge shows: reports nobody has picked up yet. */
    public function counts(): JsonResponse
    {
        return response()->json([
            'new' => ProblemReport::query()->where('status', 'new')->count(),
            'open' => ProblemReport::query()->open()->count(),
        ]);
    }

    public function show(Request $request, ProblemReport $report): JsonResponse
    {
        $report->load(['user', 'organization', 'handler', 'attachments']);

        return response()->json(['data' => (new ProblemReportResource($report))->detail($request)]);
    }

    public function update(UpdateProblemReportRequest $request, ProblemReport $report, ProblemReportTriage $triage): JsonResponse
    {
        $report = $triage->update($report, $request->validated(), $request->user());
        $report->load(['user', 'organization', 'handler', 'attachments']);

        return response()->json([
            'message' => "{$report->reference} saved.",
            'data' => (new ProblemReportResource($report))->detail($request),
        ]);
    }

    public function attachment(Request $request, ProblemReport $report, ProblemReportAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->problem_report_id === $report->id, 404);

        return FileStream::response($request, $attachment->path, 'no-store, private');
    }
}
