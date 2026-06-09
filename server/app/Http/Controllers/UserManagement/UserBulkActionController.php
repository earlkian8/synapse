<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\BulkUserActionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UserBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of users.
     */
    public function __invoke(BulkUserActionRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->message($action, (int) $affected),
        ]);

        return back();
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
