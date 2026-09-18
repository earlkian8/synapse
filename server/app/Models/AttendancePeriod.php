<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\PeriodLock;
use App\Support\Attendance\PeriodLocker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pay period attendance closes on (ADR 0039) — generated on the
 * organisation's calendar (`attendance_period_frequency`) and, once locked,
 * frozen: {@see PeriodLock} refuses every change to a day inside it, and the
 * summary {@see PeriodLocker} wrote at the moment of locking is kept as the
 * period's file, so what payroll received can always be retrieved.
 */
class AttendancePeriod extends Model
{
    use BelongsToOrganization, HasHashid;

    /** @var list<string> */
    public const STATUSES = ['open', 'locked'];

    /**
     * How often a company closes attendance. Semi-monthly (1–15, 16–end) is the
     * Philippine norm and the default.
     *
     * @var list<string>
     */
    public const FREQUENCIES = ['weekly', 'bi_weekly', 'semi_monthly', 'monthly'];

    protected $fillable = [
        'organization_id',
        'start_date',
        'end_date',
        'status',
        'locked_by',
        'locked_at',
        'lock_note',
        'unlocked_by',
        'unlocked_at',
        'unlock_reason',
        'export_path',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /**
     * Whether a work date falls inside the period.
     */
    public function covers(string $date): bool
    {
        return $date >= $this->start_date->toDateString() && $date <= $this->end_date->toDateString();
    }

    /**
     * "Sep 1 – 15" / "Aug 26 – Sep 1".
     */
    public function label(): string
    {
        $start = $this->start_date;
        $end = $this->end_date;

        return $start->month === $end->month
            ? $start->format('M j').' – '.$end->format('j')
            : $start->format('M j').' – '.$end->format('M j');
    }
}
