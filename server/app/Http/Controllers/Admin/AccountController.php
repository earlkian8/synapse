<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AccountResource;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Queries\Admin\AccountsIndexQuery;
use App\Support\Admin\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ERP sign-in accounts, read-only (ADR 0085). Deactivating or archiving an
 * account stays with each organisation's own HR, who know the person.
 */
class AccountController extends Controller
{
    public function index(Request $request, AccountsIndexQuery $query): JsonResponse
    {
        return AccountResource::collection($query->paginate($request))
            ->additional([
                'stats' => $query->stats(),
                'filters' => $query->filters($request),
            ])
            ->response();
    }

    public function show(int $account): JsonResponse
    {
        $user = User::withTrashed()->withCount('memberships')->findOrFail($account);

        $memberships = $user->memberships()
            ->withTrashed()
            ->orderByDesc('organization_user.is_default')
            ->orderBy('organizations.name')
            ->get();

        $roles = $user->roles()
            ->withoutGlobalScope(OrganizationScope::class)
            ->get()
            ->groupBy('organization_id');

        $tokens = AccountsIndexQuery::liveMobileTokens()->where('tokenable_id', $user->id);

        return response()->json([
            'data' => [
                ...(new AccountResource($user))->resolve(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'mobile_sessions' => (clone $tokens)->count(),
                'mobile_last_used_at' => Dates::iso((clone $tokens)->max('last_used_at')),
                'memberships' => $memberships->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'archived' => $organization->trashed(),
                    'is_default' => (bool) $organization->pivot->is_default,
                    'joined_at' => Dates::iso($organization->pivot->joined_at ?? $organization->pivot->created_at),
                    'roles' => ($roles->get($organization->id) ?? collect())->pluck('label')->filter()->values()->all(),
                ])->all(),
            ],
        ]);
    }
}
