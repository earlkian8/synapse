<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\AttendanceCalculator;
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
    public const STATUSES = ['present', 'late', 'undertime', 'absent', 'on_leave', 'day_off', 'holiday', 'incomplete'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'work_date',
        'work_schedule_id',
        'scheduled_start',
        'scheduled_end',
        'status',
        'first_in_at',
        'last_out_at',
        'worked_minutes',
        'break_minutes',
        'late_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'is_manual',
        'remarks',
        'approval_status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'is_manual' => 'boolean',
            'approved_at' => 'datetime',
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
     * The user who approved a correction / overtime on this record.
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
