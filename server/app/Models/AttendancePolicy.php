<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\PolicyResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How a company judges an attendance day (ADR 0038): grace, rounding, lateness
 * thresholds, overtime, breaks, night differential — a preset, adjusted through
 * typed options ({@see AttendancePolicySettings}), never a formula.
 *
 * Attached to a dated assignment, a schedule or a department, or marked the
 * company's default, and read for a given day by {@see PolicyResolver}. A day
 * freezes the policy it opened with, so editing one never re-judges history
 * until HR re-applies it. Archived rather than hard-deleted, like the other
 * Company Setup catalogues, so what points at it keeps resolving.
 */
class AttendancePolicy extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'preset_key',
        'settings',
        'settings_version',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'settings_version' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The typed settings, with every key an older save lacks given its default.
     */
    public function settings(): AttendancePolicySettings
    {
        return AttendancePolicySettings::fromArray($this->settings);
    }

    /**
     * The preset this policy was created from, when it still exists.
     *
     * @return array<string, mixed>|null
     */
    public function preset(): ?array
    {
        return AttendancePolicyPresets::find($this->preset_key);
    }

    /**
     * Schedules that judge their days by this policy.
     *
     * @return HasMany<WorkSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    /**
     * Departments that judge their people by this policy.
     *
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Assignments that single somebody out for this policy.
     *
     * @return HasMany<EmployeeScheduleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * Whether anything still points at this policy — a schedule, a department or
     * an assignment, archived or not.
     */
    public function isInUse(): bool
    {
        return WorkSchedule::withTrashed()->where('attendance_policy_id', $this->id)->exists()
            || Department::withTrashed()->where('attendance_policy_id', $this->id)->exists()
            || $this->assignments()->exists();
    }

    /**
     * Keep one default per organisation: marking this one clears the rest.
     */
    public function enforceSingleDefault(): void
    {
        if (! $this->is_default) {
            return;
        }

        static::whereKeyNot($this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
