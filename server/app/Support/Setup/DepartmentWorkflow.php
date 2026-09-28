<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\DepartmentRequest;
use App\Models\Department;
use App\Models\Position;
use App\Support\ActivityLogger;

/**
 * Everything that changes the org structure, in one place: create, edit, archive,
 * restore and permanently delete departments, and add, edit and delete the
 * positions under them.
 *
 * The Departments screen and the assistant both come through here, so the
 * structure changes, and is refused, by the same rules — in the same words
 * ({@see DepartmentException}). Validation itself (a per-tenant unique code, a
 * parent that is not inside the department's own subtree) is
 * {@see DepartmentRequest::rulesFor()}, which both run first.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class DepartmentWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): Department
    {
        $department = Department::create($data);

        $this->log('created', "Created department \"{$department->name}\"{$channel}", $department);

        return $department;
    }

    /**
     * Change a department. Only the keys given change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data, string $channel = ''): Department
    {
        $department->update($data);

        $this->log('updated', "Updated department \"{$department->name}\"{$channel}", $department);

        return $department;
    }

    /**
     * Archive (soft-delete) a department. Its people, positions and
     * sub-departments stay pointed at it, so restoring it puts everything back.
     */
    public function archive(Department $department, string $channel = ''): void
    {
        $name = $department->name;
        $department->delete();

        $this->log('archived', "Archived department \"{$name}\"{$channel}", null, $name);
    }

    /**
     * Restore an archived department — unless its code was taken while it was
     * archived (the per-tenant unique index ignores archived rows).
     *
     * @throws DepartmentException
     */
    public function restore(Department $department, string $channel = ''): void
    {
        if (Department::where('code', $department->code)->whereKeyNot($department->id)->exists()) {
            throw new DepartmentException("Another department already uses the code \"{$department->code}\".");
        }

        $department->restore();

        $this->log('restored', "Restored department \"{$department->name}\"{$channel}", $department);
    }

    /**
     * Permanently delete an archived department. Its positions, employees and
     * sub-departments are detached (their FKs null out), not deleted.
     */
    public function forceDelete(Department $department, string $channel = ''): void
    {
        $name = $department->name;
        $department->forceDelete();

        $this->log('deleted', "Permanently deleted department \"{$name}\"{$channel}", null, $name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPosition(Department $department, array $data, string $channel = ''): Position
    {
        $position = $department->positions()->create($data);

        $this->log('created', "Added position \"{$position->title}\" to {$department->name}{$channel}", $department);

        return $position;
    }

    /**
     * Change a position. Only the keys given change.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePosition(Position $position, array $data, string $channel = ''): Position
    {
        $position->update($data);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated position \"{$position->title}\"{$channel}",
            subject: $position,
            logName: 'company-setup',
            subjectLabel: $position->title,
        );

        return $position;
    }

    /**
     * Delete a position. Employees holding it keep their record (the FK nulls out).
     */
    public function deletePosition(Position $position, string $channel = ''): void
    {
        $title = $position->title;
        $position->delete();

        $this->log('deleted', "Deleted position \"{$title}\"{$channel}", null, $title);
    }

    private function log(string $event, string $description, ?Department $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'company-setup',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
