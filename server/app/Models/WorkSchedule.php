<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\DayRules;
use App\Support\Attendance\ShiftResolver;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shift **template** (ADR 0037): a type, a cycle, and one
 * {@see WorkScheduleDay} per day of that cycle. Configured under Company Setup →
 * Work Schedule & Holidays, given to people by dated assignments
 * ({@see EmployeeScheduleAssignment}) and read for a given day by
 * {@see ShiftResolver}. Archived rather than
 * hard-deleted so assigned employees keep a valid schedule.
 *
 * `start_time`, `end_time` and `work_days` are the pre-pattern columns. They are
 * kept as a read-only summary of the pattern — maintained by the editor, still
 * read by the employee screens, the mobile session and the assistant — and go
 * once every reader uses the resolver.
 */
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    /**
     * How a day on this schedule is judged.
     *
     *  - `fixed` — late against the first segment's start, undertime against the
     *    last segment's end.
     *  - `flexible` — late only after the core window opens, undertime when it
     *    closes early or the hours are short.
     *  - `hours_only` — never late; only the hours count.
     *
     * @var list<string>
     */
    public const TYPES = ['fixed', 'flexible', 'hours_only'];

    /** The longest cycle a rotation may run over — enough for a 12-week roster. */
    public const MAX_CYCLE_LENGTH_DAYS = 84;

    /** A plain week; anything else is a rotation and needs an anchor date. */
    public const WEEKLY_CYCLE_LENGTH = 7;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'cycle_length_days',
        'cycle_anchor_date',
        'start_time',
        'end_time',
        'work_days',
        'grace_minutes',
        'required_hours',
        'weekly_required_minutes',
        'attendance_policy_id',
    ];

    /**
     * Mirrors the column defaults so a freshly-made instance answers the same as
     * one read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'fixed',
        'cycle_length_days' => self::WEEKLY_CYCLE_LENGTH,
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'grace_minutes' => 'integer',
            'required_hours' => 'decimal:2',
            'cycle_length_days' => 'integer',
            'cycle_anchor_date' => 'date',
            'weekly_required_minutes' => 'integer',
        ];
    }

    /**
     * Employees currently pointed at this schedule (the denormalised pointer).
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * The cycle's days, in order.
     *
     * @return HasMany<WorkScheduleDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class)->orderBy('day_index');
    }

    /**
     * Every assignment ever made of this schedule.
     *
     * @return HasMany<EmployeeScheduleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * The policy days on this schedule are judged by, unless an assignment names
     * its own (ADR 0038). Archived policies still resolve.
     *
     * @return BelongsTo<AttendancePolicy, $this>
     */
    public function attendancePolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class)->withTrashed();
    }

    /**
     * Whether this is a rotation rather than a plain week.
     */
    public function isRotating(): bool
    {
        return $this->cycle_length_days !== self::WEEKLY_CYCLE_LENGTH;
    }

    /**
     * The day rows the resolver reads, keyed by `day_index`.
     *
     * A schedule written before day patterns existed — or made by a factory or
     * seeder that only set the old columns — has none, so one is synthesised from
     * `work_days` / `start_time` / `end_time`: the same hours on each working day,
     * a rest day everywhere else. That is exactly what the pre-pattern code did,
     * so such a schedule keeps answering the way it always has.
     *
     * @return Collection<int, WorkScheduleDay>
     */
    public function patternDays(): Collection
    {
        $days = $this->relationLoaded('days') ? $this->days : $this->days()->get();

        if ($days->isNotEmpty()) {
            return $days->keyBy('day_index');
        }

        return $this->synthesizePattern();
    }

    /**
     * Seven day rows (unsaved) standing in for a schedule that has no pattern of
     * its own.
     *
     * @return Collection<int, WorkScheduleDay>
     */
    private function synthesizePattern(): Collection
    {
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $working = is_array($this->work_days) && $this->work_days !== []
            ? $this->work_days
            : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

        $start = self::clockFace($this->start_time);
        $end = self::clockFace($this->end_time);
        $segments = $start !== null && $end !== null ? [['start' => $start, 'end' => $end]] : [];

        $required = $this->required_hours !== null
            ? (int) round((float) $this->required_hours * 60)
            : DayRules::DEFAULT_REQUIRED_MINUTES;

        $days = new Collection;

        foreach ($names as $index => $name) {
            $days->put($index + 1, new WorkScheduleDay([
                'work_schedule_id' => $this->id,
                'day_index' => $index + 1,
                'is_rest_day' => ! in_array($name, $working, true),
                'segments' => $segments,
                'required_minutes' => $required,
                'unpaid_break_minutes' => 0,
            ]));
        }

        return $days;
    }

    /**
     * A stored "HH:MM[:SS]" time as "HH:MM", or null when it is not set.
     */
    public static function clockFace(?string $time): ?string
    {
        $time = trim((string) $time);

        return $time === '' ? null : substr($time, 0, 5);
    }
}
