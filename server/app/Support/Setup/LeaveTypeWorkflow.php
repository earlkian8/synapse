<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\LeaveTypeRequest;
use App\Models\LeaveType;
use App\Support\ActivityLogger;

/**
 * Everything that changes the kinds of leave a company grants: create, edit,
 * archive, restore and permanently delete.
 *
 * The Leave Types screen and the assistant both come through here, so a type is
 * written and recorded the same way whoever asked. Validation is
 * {@see LeaveTypeRequest::rulesFor()}, which both run first. Refusals are
 * {@see LeaveTypeException}, worded to be shown as they are.
 *
 * A type's default entitlement is read live by the balances of everybody who
 * has none of their own for the year, so changing it changes theirs; filed
 * requests keep their type, archived or not.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class LeaveTypeWorkflow
{
    /**
     * The colours the Leave Types editor offers, in its order
     * (`leave-type-form-sheet.tsx`) — for a type made without the editor.
     *
     * @var list<string>
     */
    public const PALETTE = ['#0ABFBF', '#6366F1', '#F59E0B', '#10B981', '#EF4444', '#EC4899', '#8B5CF6', '#6B7280'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): LeaveType
    {
        $type = LeaveType::create($data);

        $this->log('created', "Created leave type \"{$type->name}\"{$channel}", $type);

        return $type;
    }

    /**
     * Change a type. Only the keys given change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LeaveType $type, array $data, string $channel = ''): LeaveType
    {
        $type->update($data);

        $this->log('updated', "Updated leave type \"{$type->name}\"{$channel}", $type);

        return $type;
    }

    /**
     * Archive a type. Filed requests keep it.
     */
    public function archive(LeaveType $type, string $channel = ''): void
    {
        $name = $type->name;
        $type->delete();

        $this->log('archived', "Archived leave type \"{$name}\"{$channel}", null, $name);
    }

    /**
     * Restore an archived type — unless its code was given to another while it
     * was archived (the per-tenant unique index ignores archived rows).
     *
     * @throws LeaveTypeException
     */
    public function restore(LeaveType $type, string $channel = ''): void
    {
        if (LeaveType::where('code', $type->code)->whereKeyNot($type->id)->exists()) {
            throw new LeaveTypeException("Another leave type already uses the code \"{$type->code}\".");
        }

        $type->restore();

        $this->log('restored', "Restored leave type \"{$type->name}\"{$channel}", $type);
    }

    /**
     * Permanently delete an archived type and the entitlements set for it.
     *
     * @throws LeaveTypeException while any request was filed under it
     */
    public function forceDelete(LeaveType $type, string $channel = ''): void
    {
        if ($type->requests()->exists()) {
            throw new LeaveTypeException('This leave type has leave requests and cannot be permanently deleted.');
        }

        $name = $type->name;
        $type->balances()->delete();
        $type->forceDelete();

        $this->log('deleted', "Permanently deleted leave type \"{$name}\"{$channel}", null, $name);
    }

    /**
     * The first colour of the palette no live type wears yet, so a new type
     * can be told apart on the calendar — or the first, when all are taken.
     */
    public function nextColor(): string
    {
        $taken = array_map('strtoupper', LeaveType::query()->pluck('color')->all());

        foreach (self::PALETTE as $color) {
            if (! in_array($color, $taken, true)) {
                return $color;
            }
        }

        return self::PALETTE[0];
    }

    private function log(string $event, string $description, ?LeaveType $subject, ?string $label = null): void
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
