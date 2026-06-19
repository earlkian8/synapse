<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\HolidayCalendar;
use Carbon\CarbonInterface;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A holiday in the organisation's calendar (Company Setup → Work Schedule &
 * Holidays, ERD §2). A named date with a type and an optional yearly recurrence.
 *
 * Read by {@see HolidayCalendar}: a `regular` or
 * `special_non_working` holiday is a non-working day (not charged as leave); a
 * `special_working` holiday is an ordinary working day with a premium pay rate.
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    /**
     * The kinds of holiday. `regular` / `special_non_working` are non-working;
     * `special_working` is a working day.
     *
     * @var list<string>
     */
    public const TYPES = ['regular', 'special_non_working', 'special_working'];

    /**
     * The types on which no work is expected (so they are not charged as leave).
     *
     * @var list<string>
     */
    public const NON_WORKING_TYPES = ['regular', 'special_non_working'];

    protected $fillable = [
        'organization_id',
        'name',
        'date',
        'type',
        'is_recurring',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Holidays on which no work is expected.
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeNonWorking(Builder $query): void
    {
        $query->whereIn('type', self::NON_WORKING_TYPES);
    }

    /**
     * Earliest holiday first, by calendar date.
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('date');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether this holiday falls on the given date — matching the month/day only
     * when it recurs yearly, otherwise the exact date.
     */
    public function fallsOn(CarbonInterface $date): bool
    {
        if ($this->date === null) {
            return false;
        }

        return $this->is_recurring
            ? $this->date->month === $date->month && $this->date->day === $date->day
            : $this->date->isSameDay($date);
    }
}
