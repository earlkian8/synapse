<?php

namespace App\Support\Offboarding;

use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\OffboardingProvisioner;

/**
 * Everything that changes an exit, in one place: start one, edit its details,
 * complete, cancel or reopen it, delete it — and run its clearance checklist:
 * add, edit, sign off, flag and remove items, apply a template, clear what is
 * pending in one go.
 *
 * The Offboarding screens and the assistant both come through here, so an exit
 * separates the employee, and is refused, by the same rules — in the same words
 * ({@see OffboardingException}) — however it was asked for. Seeding the
 * checklist stays {@see OffboardingProvisioner}'s job.
 *
 * The lifecycle is guarded: only an exit in progress can be completed or
 * cancelled, and only a closed one reopened. Completing moves the employee to
 * the exit type's employment status; cancelling or reopening returns them to
 * active (ADR 0016). Every change is audited; `$channel` (" via assistant") says
 * how it was made.
 */
class OffboardingWorkflow
{
    /** The lifecycle actions, and what each reads as in the audit trail. */
    public const ACTIONS = ['complete' => 'Completed', 'cancel' => 'Cancelled', 'reopen' => 'Reopened'];

    /**
     * Open an exit for an employee and seed its clearance checklist.
     *
     * @param  array{type: string, notice_date?: ?string, last_working_day?: ?string, reason?: ?string}  $attributes
     *
     * @throws OffboardingException
     */
    public function start(Employee $employee, array $attributes, ?OffboardingProgram $program, string $channel = ''): OffboardingCase
    {
        if ($employee->offboardingCase()->exists()) {
            throw new OffboardingException('That employee is already being offboarded.');
        }

        if (in_array($employee->employment_status, ['resigned', 'terminated'], true)) {
            throw new OffboardingException("{$employee->full_name} has already left.");
        }

        $case = OffboardingProvisioner::start($employee, $attributes, $program);

        ActivityLogger::log(
            event: 'created',
            description: "Started offboarding for {$employee->full_name}{$channel}",
            subject: $case,
            properties: ['type' => $case->type],
            logName: 'offboarding',
            subjectLabel: $employee->full_name,
        );

        return $case;
    }

    /**
     * Change an exit's kind, dates or reason. Only the keys given change.
     *
     * @param  array{type?: string, notice_date?: ?string, last_working_day?: ?string, reason?: ?string}  $data
     */
    public function update(OffboardingCase $case, array $data, string $channel = ''): void
    {
        $case->update(array_intersect_key($data, array_flip(['type', 'notice_date', 'last_working_day', 'reason'])));

        $this->log($case, 'updated', fn (string $name): string => "Updated offboarding for {$name}{$channel}");
    }

    /**
     * Complete, cancel or reopen an exit.
     *
     * @throws OffboardingException
     */
    public function transition(OffboardingCase $case, string $action, string $channel = ''): void
    {
        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix,employment_status');

        match ($action) {
            'complete' => $this->complete($case),
            'cancel' => $this->reactivate($case, 'cancelled', requireActive: true),
            'reopen' => $this->reactivate($case, 'clearance', requireActive: false),
            default => throw new OffboardingException('That is not something an exit can do.'),
        };

        $this->log($case, 'updated', fn (string $name): string => self::ACTIONS[$action]." offboarding for {$name}{$channel}");
    }

    /**
     * Delete an exit and its checklist. The employee's employment status is left
     * as it is — deleting a record is not un-separating somebody.
     */
    public function delete(OffboardingCase $case, string $channel = ''): void
    {
        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix');
        $name = $case->employee?->full_name ?? 'employee';

        $case->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted offboarding for {$name}{$channel}",
            logName: 'offboarding',
            subjectLabel: $name,
        );
    }

    // ── The clearance checklist ─────────────────────────────────────────────

    /**
     * Add an ad-hoc item at the end of the checklist.
     *
     * @param  array{item: string, department_id?: ?int, remarks?: ?string}  $data
     */
    public function addItem(OffboardingCase $case, array $data, string $channel = ''): ClearanceItem
    {
        $item = $case->clearanceItems()->create([
            'item' => $data['item'],
            'department_id' => $data['department_id'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'pending',
            'sort_order' => (int) $case->clearanceItems()->max('sort_order') + 1,
        ]);

        $this->log($case, 'created', fn (string $name): string => "Added “{$item->item}” to the clearance for {$name}{$channel}");

        return $item;
    }

    /**
     * Change an item's label, owning department or remarks. Only the keys given
     * change.
     *
     * @param  array{item?: string, department_id?: ?int, remarks?: ?string}  $data
     */
    public function updateItem(ClearanceItem $item, array $data, string $channel = ''): void
    {
        $item->update(array_intersect_key($data, array_flip(['item', 'department_id', 'remarks'])));

        $this->log($item->case, 'updated', fn (string $name): string => "Updated clearance item “{$item->item}” for {$name}{$channel}");
    }

    /**
     * Sign an item off, flag it, or put it back to pending — stamping who signed
     * it off, and when, only when it is cleared. Remarks change only when given.
     */
    public function setItemStatus(ClearanceItem $item, string $status, User $by, ?string $remarks = null, bool $setRemarks = false, string $channel = ''): void
    {
        $cleared = $status === 'cleared';

        $item->update([
            'status' => $status,
            'remarks' => $setRemarks ? $remarks : $item->remarks,
            'cleared_by' => $cleared ? $by->id : null,
            'cleared_at' => $cleared ? now() : null,
        ]);

        $what = match ($status) {
            'cleared' => 'Signed off',
            'flagged' => 'Flagged',
            default => 'Reset',
        };

        $this->log($item->case, 'updated', fn (string $name): string => "{$what} clearance item “{$item->item}” for {$name}{$channel}");
        $this->touchProgress($item->case);
    }

    /**
     * Take an item off the checklist.
     */
    public function removeItem(ClearanceItem $item, string $channel = ''): void
    {
        $case = $item->case;
        $label = $item->item;

        $item->delete();

        $this->log($case, 'deleted', fn (string $name): string => "Removed clearance item “{$label}” for {$name}{$channel}");
    }

    /**
     * Append every item of a clearance template that is not on the checklist
     * already (matched by label, case-insensitively).
     *
     * @return int How many were added.
     *
     * @throws OffboardingException
     */
    public function applyTemplate(OffboardingCase $case, OffboardingProgram $program, string $channel = ''): int
    {
        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix,department_id');

        $existing = $case->clearanceItems()
            ->pluck('item')
            ->map(fn (string $item): string => mb_strtolower(trim($item)))
            ->all();

        $sortOrder = (int) $case->clearanceItems()->max('sort_order');
        $added = 0;

        foreach ($program->items()->get() as $blueprint) {
            if (in_array(mb_strtolower(trim($blueprint->item)), $existing, true)) {
                continue;
            }

            $case->clearanceItems()->create([
                'item' => $blueprint->item,
                'department_id' => $blueprint->use_employee_department
                    ? $case->employee?->department_id
                    : $blueprint->department_id,
                'status' => 'pending',
                'sort_order' => ++$sortOrder,
            ]);

            $added++;
        }

        if ($added === 0) {
            throw new OffboardingException('Every item in that template is already on the checklist.');
        }

        $this->log($case, 'created', fn (string $name): string => "Applied clearance template \"{$program->name}\" ({$added} ".str('item')->plural($added)." added) for {$name}{$channel}");

        return $added;
    }

    /**
     * Sign off every pending item at once — case-wide, for one department's
     * items, or for the unassigned ones. Flagged items are deliberately left
     * alone: they are real outstanding issues.
     *
     * @param  'all'|'department'|'unassigned'  $scope
     * @return int How many were signed off.
     *
     * @throws OffboardingException
     */
    public function clearPending(OffboardingCase $case, string $scope, ?int $departmentId, User $by, string $channel = ''): int
    {
        $cleared = $case->clearanceItems()
            ->where('status', 'pending')
            ->when($scope === 'department', fn ($query) => $query->where('department_id', $departmentId))
            ->when($scope === 'unassigned', fn ($query) => $query->whereNull('department_id'))
            ->update([
                'status' => 'cleared',
                'cleared_by' => $by->id,
                'cleared_at' => now(),
            ]);

        if ($cleared === 0) {
            throw new OffboardingException('Nothing pending to clear there.');
        }

        $this->touchProgress($case);
        $this->log($case, 'updated', fn (string $name): string => "Cleared {$cleared} pending clearance ".str('item')->plural($cleared)." in bulk for {$name}{$channel}");

        return $cleared;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Finalise an exit and mark the employee separated.
     *
     * @throws OffboardingException
     */
    private function complete(OffboardingCase $case): void
    {
        if (! $case->isActive()) {
            throw new OffboardingException("This exit is already {$case->status}.");
        }

        $case->update(['status' => 'completed', 'completed_at' => now()]);

        $case->employee?->update(['employment_status' => $case->targetEmploymentStatus()]);
    }

    /**
     * Cancel an exit in progress, or reopen a closed one — either way the
     * employee is back to active.
     *
     * @throws OffboardingException
     */
    private function reactivate(OffboardingCase $case, string $status, bool $requireActive): void
    {
        if ($requireActive && ! $case->isActive()) {
            throw new OffboardingException("This exit is already {$case->status}.");
        }

        if (! $requireActive && $case->isActive()) {
            throw new OffboardingException('This exit is still in progress — there is nothing to reopen.');
        }

        $case->update(['status' => $status, 'completed_at' => null]);

        if ($case->employee && in_array($case->employee->employment_status, ['resigned', 'terminated'], true)) {
            $case->employee->update(['employment_status' => 'active']);
        }
    }

    /**
     * Nudge a case from "initiated" into "clearance" once any sign-off activity
     * has happened, so the board reflects progress without a manual status change.
     */
    private function touchProgress(OffboardingCase $case): void
    {
        if ($case->status !== 'initiated') {
            return;
        }

        if ($case->clearanceItems()->whereIn('status', ['cleared', 'flagged'])->exists()) {
            $case->update(['status' => 'clearance']);
        }
    }

    /**
     * Audit one change against the case, described in terms of the employee's
     * name (a closure, so a label like "100% of the kit" is never a format string).
     *
     * @param  callable(string): string  $describe
     */
    private function log(?OffboardingCase $case, string $event, callable $describe): void
    {
        $case?->loadMissing('employee:id,first_name,middle_name,last_name,suffix');
        $name = $case?->employee?->full_name ?? 'an employee';

        ActivityLogger::log(
            event: $event,
            description: $describe($name),
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $name,
        );
    }
}
