<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermission\StoreRoleRequest;
use App\Http\Requests\RolePermission\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Queries\RolesIndexQuery;
use App\Queries\RoleStatistics;
use App\Support\PermissionRegistry;
use App\Support\Roles\GrantRules;
use App\Support\Roles\RoleException;
use App\Support\Roles\RoleWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Display the roles & permissions listing.
     */
    public function index(Request $request, RolesIndexQuery $query, RoleStatistics $statistics): Response
    {
        [$sort, $direction] = $query->sort($request);

        return Inertia::render('system/roles/index', [
            'roles' => RoleResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'permissionGroups' => PermissionRegistry::groups(),
            // What this editor may add to a role (null: anything) — see GrantRules.
            'grantable' => GrantRules::grantable($request->user())?->values()->all(),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'type' => $query->type($request),
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(StoreRoleRequest $request, RoleWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $workflow->create($validated['label'], $validated['name'], $validated['description'] ?? null, $validated['permissions'] ?? [], $request->user());
        } catch (RoleException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Role created.');
    }

    /**
     * Update the given role and its granted permissions.
     */
    public function update(UpdateRoleRequest $request, Role $role, RoleWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $workflow->update($role, $validated['label'], $validated['description'] ?? null, $validated['permissions'] ?? [], $request->user());
        } catch (RoleException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Role updated.');
    }

    /**
     * Delete the given role.
     */
    public function destroy(Role $role, RoleWorkflow $workflow): RedirectResponse
    {
        try {
            $workflow->delete($role);
        } catch (RoleException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Role deleted.');
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
