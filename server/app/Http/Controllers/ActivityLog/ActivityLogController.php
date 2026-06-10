<?php

namespace App\Http\Controllers\ActivityLog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Queries\ActivityLogsIndexQuery;
use App\Queries\ActivityLogStatistics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    /**
     * Display the activity log listing.
     */
    public function index(Request $request, ActivityLogsIndexQuery $query, ActivityLogStatistics $statistics): Response
    {
        [$sort, $direction] = $query->sort($request);

        return Inertia::render('system/activity-logs/index', [
            'logs' => ActivityLogResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'event' => $query->event($request),
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Delete a single log entry.
     */
    public function destroy(ActivityLog $activityLog): RedirectResponse
    {
        $activityLog->delete();

        return $this->respond('Log entry deleted.');
    }

    /**
     * Delete every log entry.
     */
    public function clear(): RedirectResponse
    {
        ActivityLog::query()->delete();

        return $this->respond('Activity log cleared.');
    }

    /**
     * Flash a toast and bounce back to the listing.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
