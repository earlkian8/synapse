<?php

namespace App\Http\Controllers\ActivityLog;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivityLog\BulkActivityLogActionRequest;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ActivityLogBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of log entries.
     */
    public function __invoke(BulkActivityLogActionRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');

        $affected = match ($request->validated('action')) {
            'delete' => ActivityLog::whereIn('id', $ids)->delete(),
        };

        $noun = $affected === 1 ? 'log entry' : 'log entries';

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$affected} {$noun} deleted.",
        ]);

        return back();
    }
}
