<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStatusRequest;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Queries\Admin\AdminsIndexQuery;
use App\Support\Admin\AdminAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin accounts, for super admins (ADR 0085). The rules — never on oneself,
 * never without an active super admin — live in {@see AdminAccounts}.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AdminAccounts $accounts) {}

    public function index(Request $request, AdminsIndexQuery $query): JsonResponse
    {
        return AdminResource::collection($query->paginate($request))
            ->additional([
                'stats' => $query->stats(),
                'filters' => [...$query->filters($request), 'role' => $query->role($request)],
                'roles' => collect(Admin::ROLES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            ])
            ->response();
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        [$admin, $password] = $this->accounts->create($request->validated(), $request->user());

        return response()->json([
            'message' => "{$admin->name} can now sign in.",
            'admin' => new AdminResource($admin),
            'temporary_password' => $password,
        ], 201);
    }

    public function update(UpdateAdminRequest $request, Admin $admin): JsonResponse
    {
        $admin = $this->accounts->update($admin, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Admin updated.',
            'admin' => new AdminResource($admin),
        ]);
    }

    public function status(AdminStatusRequest $request, Admin $admin): JsonResponse
    {
        $admin = $this->accounts->setActive($admin, $request->boolean('is_active'), $request->user());

        return response()->json([
            'message' => $admin->is_active ? "{$admin->name} can sign in again." : "{$admin->name} is deactivated and signed out.",
            'admin' => new AdminResource($admin),
        ]);
    }

    public function resetPassword(Request $request, Admin $admin): JsonResponse
    {
        $password = $this->accounts->resetPassword($admin, $request->user());

        return response()->json([
            'message' => "{$admin->name}'s password was reset and their sessions ended.",
            'admin' => new AdminResource($admin->refresh()),
            'temporary_password' => $password,
        ]);
    }

    public function resetTwoFactor(Request $request, Admin $admin): JsonResponse
    {
        $this->accounts->resetTwoFactor($admin, $request->user());

        return response()->json([
            'message' => "{$admin->name} will set up a new authenticator at their next sign-in.",
            'admin' => new AdminResource($admin->refresh()),
        ]);
    }

    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        $this->accounts->delete($admin, $request->user());

        return response()->json(['message' => 'Admin deleted.']);
    }
}
