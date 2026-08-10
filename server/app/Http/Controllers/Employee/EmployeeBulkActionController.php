<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkEmployeeActionRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\EmployeeInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

class EmployeeBulkActionController extends Controller
{
    /**
     * Apply an action to a batch of employees.
     */
    public function __invoke(BulkEmployeeActionRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

        Gate::authorize($this->permissionFor($action));

        $ids = $request->validated('ids');

        $affected = match ($action) {
            'archive' => Employee::whereIn('id', $ids)->delete(),
            'restore' => Employee::onlyTrashed()->whereIn('id', $ids)->restore(),
            'delete' => Employee::withTrashed()->whereIn('id', $ids)->forceDelete(),
            'set-status' => Employee::whereIn('id', $ids)
                ->update(['employment_status' => $request->validated('status')]),
            'invite' => $this->invite($ids, $request->user()),
        };

        ActivityLogger::log(
            event: $this->eventFor($action),
            description: 'Bulk '.$this->eventFor($action)." {$affected} ".((int) $affected === 1 ? 'employee' : 'employees'),
            properties: ['action' => $action, 'count' => (int) $affected, 'ids' => $ids],
            logName: 'employees',
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->message($action, (int) $affected),
        ]);

        return back();
    }

    /**
     * Invite a batch of employees to the app, skipping the ones that cannot be
     * invited (already have access, no address on file) rather than failing the
     * whole batch for them. Returns how many invitations actually went out.
     *
     * @param  list<int>  $ids
     */
    private function invite(array $ids, User $actor): int
    {
        $sent = 0;

        foreach (Employee::whereIn('id', $ids)->whereNull('user_id')->get() as $employee) {
            try {
                EmployeeInvitations::invite($employee, $actor);
                $sent++;
            } catch (RuntimeException) {
                // Nothing to send to, or somebody claimed the line meanwhile.
            }
        }

        return $sent;
    }

    /**
     * Map a bulk action to the permission it requires.
     */
    private function permissionFor(string $action): string
    {
        return match ($action) {
            'archive' => 'employees.delete',
            'restore' => 'employees.restore',
            'delete' => 'employees.force-delete',
            'set-status' => 'employees.update',
            'invite' => 'employees.invite',
        };
    }

    /**
     * Map a bulk action to its activity-log event.
     */
    private function eventFor(string $action): string
    {
        return match ($action) {
            'archive' => 'archived',
            'restore' => 'restored',
            'delete' => 'deleted',
            'set-status' => 'updated',
            'invite' => 'created',
        };
    }

    /**
     * Build a human-friendly result message.
     */
    private function message(string $action, int $count): string
    {
        $noun = $count === 1 ? 'employee' : 'employees';

        return match ($action) {
            'archive' => "{$count} {$noun} archived.",
            'restore' => "{$count} {$noun} restored.",
            'delete' => "{$count} {$noun} permanently deleted.",
            'set-status' => "{$count} {$noun} updated.",
            'invite' => $count === 0
                ? 'Nobody was invited — those employees already have access, or have no email address on file.'
                : "{$count} {$noun} invited to the app.",
        };
    }
}
