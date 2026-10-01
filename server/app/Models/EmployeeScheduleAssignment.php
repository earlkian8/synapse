<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which schedule an employee works, and from when (ADR 0037).
 *
 * Before this, `employees.work_schedule_id` was the whole answer, so moving
 * somebody to another shift rewrote what their last three months had been judged
 * against. An assignment has an effective range instead: a change takes effect on
 * its date and the record before it keeps its own shift.
 *
 * Ranges never overlap for one employee — {@see ScheduleAssigner} closes the
 * previous open-ended row rather than adding a second.
 */
class EmployeeScheduleAssignment extends Model
{
    use BelongsToOrganization, HasHashid;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'work_schedule_id',
        'effective_from',
        'effective_to',
        'cycle_offset',
        'attendance_policy_id',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'cycle_offset' => 'integer',
            'attendance_policy_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function workSchedule(): BelongsTo
    {
        // withTrashed so an assignment to an archived schedule still resolves.
        return $this->belongsTo(WorkSchedule::class)->withTrashed();
    }

    /**
     * The policy this person is judged by while the assignment runs, when it is
     * not their shift's (ADR 0038).
     *
     * @return BelongsTo<AttendancePolicy, $this>
     */
    public function attendancePolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Assignments in force on a date — `effective_from` has passed and
     * `effective_to` has not (or is open-ended).
     *
     * @param  Builder<static>  $query
     */
    public function scopeCovering(Builder $query, string $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * Assignments that touch any part of [from, to].
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->whereDate('effective_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));
    }
}
