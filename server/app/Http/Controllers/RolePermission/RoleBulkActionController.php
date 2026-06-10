<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermission\BulkRoleActionRequest;
use App\Models\Role;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RoleBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of roles.
     */
    public function __invoke(BulkRoleActionRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

        // Each bulk action requires the same permission as its single-row form.
        Gate::authorize($this->permissionFor($action));

        $requested = Role::whereIn('id', $request->validated('ids'))->get();

        // Built-in (system / super-admin) roles can never be removed in a sweep.
        $roles = $requested->where('is_system', false)->values();
        $protected = $requested->count() - $roles->count();

        // All selected roles were protected — nothing to do, but still confirm it.
        if ($roles->isEmpty()) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => $protected === 1
                    ? 'That role is built-in and cannot be deleted.'
                    : 'Those roles are built-in and cannot be deleted.',
            ]);

            return back();
        }

        $labels = $roles->pluck('label')->all();
        $count = $roles->count();

        Role::whereKey($roles->pluck('id'))->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Bulk deleted '.$count.' '.($count === 1 ? 'role' : 'roles'),
            properties: ['action' => $action, 'count' => $count, 'roles' => $labels, 'protected' => $protected],
            logName: 'roles',
        );

        $noun = $count === 1 ? 'role' : 'roles';
        $message = "{$count} {$noun} deleted.";

        // Tell the user when part of their selection was left untouched.
        if ($protected > 0) {
            $message .= ' '.$protected.' built-in '
                .($protected === 1 ? 'role was' : 'roles were').' skipped.';
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return back();
    }

    /**
     * Map a bulk action to the permission it requires.
     */
    private function permissionFor(string $action): string
    {
        return match ($action) {
            'delete' => 'roles.delete',
        };
    }
}
