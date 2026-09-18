<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\AttendanceRequestApprover;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Something an employee asks attendance to know that the clock could not
 * capture (ADR 0039): a missed or wrong punch, overtime, a day on official
 * business, a day worked remotely. A reviewer decides it through
 * {@see AttendanceRequestApprover} — the one place any decision is applied.
 *
 * The ask itself is the `payload`, whose shape depends on the type:
 *
 *  - `correction` — the punches as they should read, each optional: `time_in`,
 *    `break_start`, `break_end`, `time_out` (clock-face "HH:MM" on the
 *    organisation's clock). A time given replaces that punch; one left out keeps
 *    the punch the day has.
 *  - `overtime` — `minutes` asked for, and `pre_approval` when it was filed
 *    before the day.
 *  - `official_business` / `remote_work` — optional `start_time` / `end_time`
 *    and a `location` note, across `start_date`–`end_date`.
 */
class AttendanceRequest extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** @var list<string> */
    public const TYPES = ['correction', 'overtime', 'official_business', 'remote_work'];

    /** The types that concern a single day. */
    public const SINGLE_DAY_TYPES = ['correction', 'overtime'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /** The fields a correction can propose, in the order a day happens. */
    public const CORRECTION_FIELDS = ['time_in', 'break_start', 'break_end', 'time_out'];

    /** The widest range one official-business or remote-work request covers. */
    public const MAX_RANGE_DAYS = 31;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'attendance_record_id',
        'payload',
        'reason',
        'attachment',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_note',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payload' => 'array',
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The punches an approved correction replaced — soft-deleted, kept for the
     * trail.
     *
     * @return HasMany<AttendancePunch, $this>
     */
    public function replacedPunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'replaced_by_request_id')
            ->withTrashed()
            ->orderBy('punched_at')
            ->orderBy('id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Every date the request covers, as "Y-m-d".
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = CarbonImmutable::parse($this->start_date->toDateString()); $day->lte(CarbonImmutable::parse($this->end_date->toDateString())); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * The overtime asked for, in minutes (overtime requests only).
     */
    public function requestedMinutes(): int
    {
        return (int) ($this->payload['minutes'] ?? 0);
    }

    /**
     * The attachment as a servable URL, when one was given.
     */
    public function attachmentUrl(): ?string
    {
        return $this->attachment ? Storage::disk('public')->url($this->attachment) : null;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Requests whose dates touch [from, to].
     *
     * @param  Builder<AttendanceRequest>  $query
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    /**
     * Free-text search by the employee the request is about.
     *
     * @param  Builder<AttendanceRequest>  $query
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
