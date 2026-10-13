<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminActivityResource;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Queries\Admin\AdminActivityIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin activity log (ADR 0085), for super admins. Read-only by design:
 * there is no route that edits or deletes an entry.
 */
class ActivityLogController extends Controller
{
    public function __invoke(Request $request, AdminActivityIndexQuery $query): JsonResponse
    {
        return AdminActivityResource::collection($query->paginate($request))
            ->additional([
                'stats' => $query->stats(),
                'filters' => [
                    ...$query->filters($request),
                    'event' => $query->event($request),
                    'admin_id' => $query->adminId($request),
                ],
                'events' => collect(AdminActivityLog::EVENTS)
                    ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                    ->values(),
                'admins' => Admin::query()->orderBy('name')->get(['id', 'name', 'email']),
            ])
            ->response();
    }
}
