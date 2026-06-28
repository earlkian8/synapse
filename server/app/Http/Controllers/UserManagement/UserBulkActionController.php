<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\BulkUserActionRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UserBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of users.
     */
    public function __invoke(BulkUserActionRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

        // Each bulk action requires the same permission as its single-row form.
        Gate::authorize($this->permissionFor($action));

        // Never let an admin lock or remove themselves in a bulk sweep.
        $ids = collect($request->validated('ids'))
            ->reject(fn ($id) => (int) $id === $request->user()->id)
            ->values();

        if ($ids->isEmpty()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Nothing to update.']);

            return back();
        }

        // Confine every sweep to members of the active tenant — ids come from the
        // client and users are global identities now (ADR 0023).
        $affected = match ($action) {
            'activate' => User::query()->inCurrentOrganization()->whereIn('id', $ids)->update(['is_active' => true]),
            'deactivate' => User::query()->inCurrentOrganization()->whereIn('id', $ids)->update(['is_active' => false]),
            'archive' => User::query()->inCurrentOrganization()->whereIn('id', $ids)->delete(),
            'restore' => User::onlyTrashed()->inCurrentOrganization()->whereIn('id', $ids)->restore(),
            'delete' => User::withTrashed()->inCurrentOrganization()->whereIn('id', $ids)->forceDelete(),
            'assign-role' => $this->assignRole($ids->all(), (int) $request->validated('role_id')),
        };

        ActivityLogger::log(
            event: $this->eventFor($action),
            description: 'Bulk '.$this->eventFor($action)." {$affected} ".((int) $affected === 1 ? 'user' : 'users'),
            properties: ['action' => $action, 'count' => (int) $affected, 'ids' => $ids->all()],
            logName: 'user_management',
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->message($action, (int) $affected),
        ]);

        return back();
    }

    /**
     * Attach a role to each of the given users without disturbing the roles they
     * already hold, then forget any cached permissions so access reflects the
     * change immediately. Returns how many users gained the role.
     *
     * @param  list<int>  $ids
     */
    private function assignRole(array $ids, int $roleId): int
    {
        $role = Role::findOrFail($roleId);
        $affected = 0;

        User::query()->inCurrentOrganization()->whereIn('id', $ids)->with('roles:id')->each(function (User $user) use ($role, &$affected) {
            if ($user->roles->contains($role->id)) {
                return;
            }

            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->forgetCachedPermissions();
            $affected++;
        });

        return $affected;
    }

    /**
     * Map a bulk action to the permission it requires.
     */
    private function permissionFor(string $action): string
    {
        return match ($action) {
            'activate', 'deactivate' => 'users.manage-status',
            'archive' => 'users.delete',
            'restore' => 'users.restore',
            'delete' => 'users.force-delete',
            'assign-role' => 'roles.assign',
        };
    }

    /**
     * Map a bulk action to its canonical activity-log event.
     */
    private function eventFor(string $action): string
    {
        return match ($action) {
            'activate' => 'activated',
            'deactivate' => 'deactivated',
            'archive' => 'archived',
            'restore' => 'restored',
            'delete' => 'deleted',
            'assign-role' => 'updated',
        };
    }

    /**
     * Build a human-friendly result message.
     */
    private function message(string $action, int $count): string
    {
        $noun = $count === 1 ? 'user' : 'users';

        return match ($action) {
            'activate' => "{$count} {$noun} activated.",
            'deactivate' => "{$count} {$noun} deactivated.",
            'archive' => "{$count} {$noun} archived.",
            'restore' => "{$count} {$noun} restored.",
            'delete' => "{$count} {$noun} permanently deleted.",
            'assign-role' => $count === 0
                ? 'Selected users already have that role.'
                : "Role assigned to {$count} {$noun}.",
        };
    }
}
