<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Queries\Admin\OrganizationsIndexQuery;
use App\Support\Admin\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organisations, read-only (ADR 0085): the portal watches them, it never edits
 * one. Archived organisations stay reachable so the record still opens.
 */
class OrganizationController extends Controller
{
    public function index(Request $request, OrganizationsIndexQuery $query): JsonResponse
    {
        return OrganizationResource::collection($query->paginate($request))
            ->additional([
                'stats' => $query->stats(),
                'filters' => $query->filters($request),
            ])
            ->response();
    }

    public function show(int $organization): JsonResponse
    {
        $record = OrganizationsIndexQuery::withMeasures(Organization::withTrashed())->findOrFail($organization);
        $id = $record->id;

        $members = $record->members()
            ->with(['roles' => fn ($roles) => $roles->withoutGlobalScope(OrganizationScope::class)->where('roles.organization_id', $id)])
            ->orderByDesc('organization_user.created_at')
            ->limit(10)
            ->get();

        // The company's HR Managers — who it would be to call.
        $owners = User::query()
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('roles.organization_id', $id)
                ->where('roles.name', Role::SUPER_ADMIN))
            ->orderBy('last_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                ...(new OrganizationResource($record))->resolve(),
                'phone' => $record->phone,
                'address' => $record->address,
                'departments_count' => Department::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $id)->count(),
                'pending_join_requests' => OrganizationJoinRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $id)->pending()->count(),
                'activity_30d' => ActivityLog::query()->withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $id)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
                'owners' => $owners->map(fn (User $user): array => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ])->all(),
                'members' => $members->map(fn (User $user): array => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'is_active' => (bool) $user->is_active,
                    'joined_at' => Dates::iso($user->pivot->joined_at ?? $user->pivot->created_at),
                    'roles' => $user->roles->pluck('label')->filter()->values()->all(),
                ])->all(),
            ],
        ]);
    }
}
