<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\AttendancePolicyRequest;
use App\Models\AttendancePolicy;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the attendance policies (ADR 0038): create, edit,
 * make or stop being the company default, archive, restore and permanently
 * delete. Refusals are {@see AttendancePolicyException}, worded to be shown as
 * they are.
 *
 * The Attendance Policies screen and the assistant both come through here, so
 * there is one default at a time and every change is recorded the same way
 * whoever asked. Validation is {@see AttendancePolicyRequest} — its rules,
 * settings rules and cross-field checks — which both run first, and the
 * settings arrive already canonical.
 *
 * Editing a policy never re-judges a day already recorded: a day freezes the
 * policy it opened with until HR re-applies it.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class AttendancePolicyWorkflow
{
    /**
     * @param  array<string, mixed>  $attributes  Name, description, preset, canonical settings and version, and optionally is_default.
     */
    public function create(array $attributes, string $channel = ''): AttendancePolicy
    {
        $policy = DB::transaction(function () use ($attributes): AttendancePolicy {
            $policy = AttendancePolicy::create($attributes);
            $policy->enforceSingleDefault();

            return $policy;
        });

        $this->log(
            'created',
            "Created attendance policy \"{$policy->name}\"".($policy->is_default ? ' as the company default' : '').$channel,
            $policy,
        );

        return $policy;
    }

    /**
     * Change a policy. Only the columns given change — and `is_default` only when
     * it is one of them, so saving a policy's rules never quietly stops it being
     * the default.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(AttendancePolicy $policy, array $attributes, string $channel = ''): AttendancePolicy
    {
        DB::transaction(function () use ($policy, $attributes): void {
            $policy->update($attributes);
            $policy->enforceSingleDefault();
        });

        $this->log('updated', "Updated attendance policy \"{$policy->name}\"{$channel}", $policy);

        return $policy;
    }

    /**
     * Make a policy the company default — what anyone whose assignment, schedule,
     * department and work location name none is judged by — or stop it being
     * one, which drops those people back to the built-in rules.
     */
    public function setDefault(AttendancePolicy $policy, bool $default, string $channel = ''): void
    {
        DB::transaction(function () use ($policy, $default): void {
            $policy->forceFill(['is_default' => $default])->save();
            $policy->enforceSingleDefault();
        });

        $this->log(
            'updated',
            $default
                ? "Set \"{$policy->name}\" as the company's default attendance policy{$channel}"
                : "Cleared \"{$policy->name}\" as the company's default attendance policy{$channel}",
            $policy,
        );
    }

    /**
     * Archive a policy. It stops being anybody's default; what names it directly
     * — a schedule, a department, an assignment — keeps resolving to it.
     */
    public function archive(AttendancePolicy $policy, string $channel = ''): void
    {
        $name = $policy->name;

        $policy->forceFill(['is_default' => false])->save();
        $policy->delete();

        $this->log('archived', "Archived attendance policy \"{$name}\"{$channel}", null, $name);
    }

    /**
     * Restore an archived policy — unless its name was given to another while it
     * was archived. Names are unique among live policies only (the editor
     * enforces it; there is no index), so restoring it would leave two live
     * policies of one name, neither of which the editor would then save.
     *
     * @throws AttendancePolicyException
     */
    public function restore(AttendancePolicy $policy, string $channel = ''): void
    {
        if (AttendancePolicy::query()->whereRaw('lower(name) = ?', [mb_strtolower($policy->name)])->exists()) {
            throw new AttendancePolicyException("Another policy is already called \"{$policy->name}\". Rename one of them first.");
        }

        $policy->restore();

        $this->log('restored', "Restored attendance policy \"{$policy->name}\"{$channel}", $policy);
    }

    /**
     * @throws AttendancePolicyException when something still names it
     */
    public function forceDelete(AttendancePolicy $policy, string $channel = ''): void
    {
        if ($policy->isInUse()) {
            throw new AttendancePolicyException('A schedule, department or assignment still uses this policy, so it cannot be permanently deleted.');
        }

        $name = $policy->name;
        $policy->forceDelete();

        $this->log('deleted', "Permanently deleted attendance policy \"{$name}\"{$channel}", null, $name);
    }

    private function log(string $event, string $description, ?AttendancePolicy $subject, ?string $label = null): void
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
