<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermission\StoreRoleRequest;
use App\Http\Requests\RolePermission\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Queries\RolesIndexQuery;
use App\Queries\RoleStatistics;
use App\Support\ActivityLogger;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            'name' => $validated['name'],
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($this->permissionIds($validated['permissions'] ?? []));

        ActivityLogger::log(
            event: 'created',
            description: "Created role {$role->label}",
            subject: $role,
            properties: ['permissions' => $validated['permissions'] ?? []],
            logName: 'roles',
            subjectLabel: $role->label,
        );

        return $this->respond('Role created.');
    }

    /**
     * Update the given role and its granted permissions.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->isSuperAdmin()) {
            return $this->respond('The Super Admin role cannot be modified.', 'error');
        }

        $validated = $request->validated();

        $role->update([
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($this->permissionIds($validated['permissions'] ?? []));

        ActivityLogger::log(
            event: 'updated',
            description: "Updated role {$role->label}",
            subject: $role,
            properties: ['permissions' => $validated['permissions'] ?? []],
            logName: 'roles',
            subjectLabel: $role->label,
        );

        return $this->respond('Role updated.');
    }

    /**
     * Delete the given role.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return $this->respond('Built-in system roles cannot be deleted.', 'error');
        }

        $label = $role->label;
        $role->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted role {$label}",
            logName: 'roles',
            subjectLabel: $label,
        );

        return $this->respond('Role deleted.');
    }

    /**
     * Resolve permission ids from a list of permission names.
     *
     * @param  list<string>  $names
     * @return Collection<int, int>
     */
    private function permissionIds(array $names): Collection
    {
        return Permission::whereIn('name', $names)->pluck('id');
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
