<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\DayRules;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee's Daily Time Record for one day — the computed summary built from
 * its {@see AttendancePunch} events. Status and the minute totals are derived
 * server-side (see {@see AttendanceCalculator}); they are
 * never trusted from the client.
 *
 * The day carries what it is judged against (ADR 0036): the shift as instants
 * (`scheduled_start_at` / `scheduled_end_at`, worked out in the organisation's
 * zone) and the {@see DayRules} frozen when it opened (`rules`) — which, from
 * ADR 0038, include the attendance policy. The minutes are split into buckets a
 * payroll export reads: `regular + overtime = worked`, with `night`, `rest_day`
 * and `holiday` as tags over those same minutes; `flags` says everything more
 * specific than the status.
 */
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    /**
     * The derived daily states.
     *
     * @var list<string>
     */
    public const STATUSES = ['present', 'late', 'undertime', 'half_day', 'absent', 'on_leave', 'day_off', 'holiday', 'incomplete'];

    /**
     * Statuses that mean the employee turned up for work that day — for
     * attendance rates and "days worked". A half day was attended, just not in
     * full (ADR 0038).
     *
     * @var list<string>
     */
    public const PRESENT_STATUSES = ['present', 'late', 'undertime', 'half_day', 'incomplete'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'work_date',
        'work_schedule_id',
        'scheduled_start',
        'scheduled_end',
        'scheduled_start_at',
        'scheduled_end_at',
        'rules',
        'status',
        'flags',
        'first_in_at',
        'last_out_at',
        'worked_minutes',
        'break_minutes',
        'late_minutes',
        'excused_late_minutes',
        'undertime_minutes',
        'regular_minutes',
        'overtime_minutes',
        'approved_overtime_minutes',
        'night_minutes',
        'rest_day_minutes',
        'holiday_minutes',
        'is_manual',
        'remarks',
        'approval_status',
        'approved_by',
        'approved_at',
        'signed_off_overtime_minutes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'rules' => 'array',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'excused_late_minutes' => 'integer',
            'regular_minutes' => 'integer',
            'approved_overtime_minutes' => 'integer',
            'night_minutes' => 'integer',
            'rest_day_minutes' => 'integer',
            'holiday_minutes' => 'integer',
            'flags' => 'array',
            'is_manual' => 'boolean',
            'approved_at' => 'datetime',
            'signed_off_overtime_minutes' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

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
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * The day's punch events, in chronological order.
     *
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class)->orderBy('punched_at')->orderBy('id');
    }

    /**
     * Punches an edit or an approved correction replaced — kept, soft-deleted,
     * so the day's trail survives (ADR 0039).
     *
     * @return HasMany<AttendancePunch, $this>
     */
    public function replacedPunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class)->onlyTrashed()->orderBy('punched_at')->orderBy('id');
    }

    /**
     * The user who signed the day off — approving what needed review on it, such
     * as overtime awaiting approval (ADR 0039).
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the employee is currently clocked in (an unmatched clock_in), i.e.
     * the day is still open.
     */
    public function isOpen(): bool
    {
        return $this->first_in_at !== null && $this->last_out_at === null;
    }

    /**
     * The attendance requests that concern this day: a correction or overtime for
     * its date, and official business or remote work whose range covers it.
     *
     * @return Builder<AttendanceRequest>
     */
    public function relatedRequests(): Builder
    {
        $date = $this->work_date->toDateString();

        return AttendanceRequest::query()
            ->where('employee_id', $this->employee_id)
            ->overlapping($date, $date)
            ->orderByDesc('created_at');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  Builder<AttendanceRecord>  $query
     */
    public function scopeForDate(Builder $query, string $date): void
    {
        $query->whereDate('work_date', $date);
    }

    /**
     * Free-text search by the owning employee.
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->whereHas('employee', fn (Builder $q) => $q->search($term));
    }
}
