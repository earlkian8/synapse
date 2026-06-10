<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\BulkUserActionRequest;
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

        $affected = match ($action) {
            'activate' => User::whereIn('id', $ids)->update(['is_active' => true]),
            'deactivate' => User::whereIn('id', $ids)->update(['is_active' => false]),
            'archive' => User::whereIn('id', $ids)->delete(),
            'restore' => User::onlyTrashed()->whereIn('id', $ids)->restore(),
            'delete' => User::withTrashed()->whereIn('id', $ids)->forceDelete(),
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
     * Map a bulk action to the permission it requires.
     */
    private function permissionFor(string $action): string
    {
        return match ($action) {
            'activate', 'deactivate' => 'users.manage-status',
            'archive' => 'users.delete',
            'restore' => 'users.restore',
            'delete' => 'users.force-delete',
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
        };
    }
}
