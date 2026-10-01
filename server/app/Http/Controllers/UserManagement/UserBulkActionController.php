<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\BulkUserActionRequest;
use App\Models\Role;
use App\Support\Roles\RoleException;
use App\Support\Users\UserAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UserBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of users.
     */
    public function __invoke(BulkUserActionRequest $request, UserAccounts $accounts): RedirectResponse
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

        // Every sweep is confined to members of the active tenant — ids come from
        // the client and users are global identities (ADR 0023) — and each account
        // passes the same rules as its single-row action (ADR 0057).
        try {
            $result = $accounts->bulk(
                $action,
                $ids->map(fn ($id): int => (int) $id)->all(),
                $request->user(),
                $action === 'assign-role' ? Role::findOrFail((int) $request->validated('role_id')) : null,
            );
        } catch (RoleException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        $message = $this->message($action, $result['affected']);

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped: {$result['reason']}";
        }

        Inertia::flash('toast', [
            'type' => $result['skipped'] > 0 ? 'warning' : 'success',
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
            'activate', 'deactivate' => 'users.manage-status',
            'archive' => 'users.delete',
            'restore' => 'users.restore',
            'delete' => 'users.force-delete',
            'assign-role' => 'roles.assign',
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
