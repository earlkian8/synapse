<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\HolidayCalendar;
use App\Support\MovableDates;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A holiday in the organisation's calendar (Company Setup → Work Schedule &
 * Holidays, ERD §2). A named date with a type and a rule for how it repeats:
 * not at all, on the same date every year, a number of days from Easter (Holy
 * Week), or on the n-th weekday of a month (ADR 0078). A holiday tied to sites
 * is local: kept only by the people based at one of them.
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

    /**
     * How a holiday repeats: `none` (its date only), `yearly` (the same month and
     * day), `easter` (days from Easter Sunday) or `weekday` (the n-th weekday of
     * a month).
     *
     * @var list<string>
     */
    public const RECURRENCES = ['none', 'yearly', 'easter', 'weekday'];

    /**
     * The rules whose date moves from year to year.
     *
     * @var list<string>
     */
    public const MOVABLE = ['easter', 'weekday'];

    /** "Last" in a weekday rule's `week`. */
    public const LAST_WEEK = -1;

    protected $fillable = [
        'organization_id',
        'name',
        'date',
        'type',
        'is_recurring',
        'recurrence',
        'rule',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_recurring' => 'boolean',
            'rule' => 'array',
        ];
    }

    /**
     * Keep `is_recurring` and `recurrence` saying the same thing. A caller that
     * only speaks `is_recurring` (as everything did before ADR 0078) turns a
     * fixed date into a yearly one or back; a movable holiday's `date` is kept on
     * its next occurrence, so lists that sort by it stay in order.
     */
    protected static function booted(): void
    {
        static::saving(function (Holiday $holiday): void {
            if ($holiday->isDirty('is_recurring') && ! $holiday->isDirty('recurrence')) {
                if (! $holiday->is_recurring) {
                    $holiday->recurrence = 'none';
                } elseif (in_array($holiday->recurrence, [null, 'none'], true)) {
                    $holiday->recurrence = 'yearly';
                }
            }

            $holiday->recurrence = in_array($holiday->recurrence, self::RECURRENCES, true) ? $holiday->recurrence : 'none';
            $holiday->is_recurring = $holiday->recurrence !== 'none';

            if (! $holiday->isMovable()) {
                $holiday->rule = null;
            } elseif ($holiday->date === null || $holiday->isDirty(['recurrence', 'rule'])) {
                $holiday->date = $holiday->nextOccurrence(CarbonImmutable::parse(OrganizationClock::today()));
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The sites a local holiday is kept at. None: it is kept everywhere.
     *
     * @return BelongsToMany<WorkLocation, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(WorkLocation::class, 'holiday_work_location')->withTimestamps();
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
     * The holidays kept by somebody based at a site — the ones kept everywhere,
     * and the site's own. With no site, only the ones kept everywhere.
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeKeptAt(Builder $query, ?int $locationId): void
    {
        $query->where(function (Builder $query) use ($locationId): void {
            $query->whereDoesntHave('locations');

            if ($locationId !== null) {
                $query->orWhereHas('locations', fn (Builder $q) => $q->whereKey($locationId));
            }
        });
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
     * Whether this holiday falls on the given date: its own date, the same
     * month and day of any year, or wherever its movable rule puts it that year.
     */
    public function fallsOn(CarbonInterface $date): bool
    {
        return $this->occurrenceIn($date->year)?->isSameDay($date) ?? false;
    }

    /**
     * The date this holiday falls on in a year, or null when it does not fall in
     * it — a one-off in another year, or a yearly 29 February outside a leap year.
     */
    public function occurrenceIn(int $year): ?CarbonImmutable
    {
        $rule = $this->rule ?? [];

        return match ($this->recurrence ?? ($this->is_recurring ? 'yearly' : 'none')) {
            'easter' => MovableDates::fromEaster($year, (int) ($rule['offset'] ?? 0)),
            'weekday' => MovableDates::nthWeekday($year, (int) ($rule['month'] ?? 1), (int) ($rule['weekday'] ?? 1), (int) ($rule['week'] ?? 1)),
            'yearly' => $this->date !== null && checkdate($this->date->month, $this->date->day, $year)
                ? CarbonImmutable::create($year, $this->date->month, $this->date->day)
                : null,
            default => $this->date !== null && $this->date->year === $year
                ? CarbonImmutable::parse($this->date->toDateString())
                : null,
        };
    }

    /**
     * The first date on or after `$from` the holiday falls on — null for a
     * one-off that has passed.
     */
    public function nextOccurrence(CarbonInterface $from): ?CarbonImmutable
    {
        $from = CarbonImmutable::instance($from)->startOfDay();

        for ($year = $from->year; $year <= $from->year + 4; $year++) {
            $date = $this->occurrenceIn($year);

            if ($date !== null && $date->gte($from)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Whether the date moves from year to year.
     */
    public function isMovable(): bool
    {
        return in_array($this->recurrence, self::MOVABLE, true);
    }

    /**
     * How the holiday repeats, in words: "Every year on Dec 25", "Every year,
     * 3 days before Easter", "Every year on the last Monday of August", "Once".
     */
    public function repeatsLabel(): string
    {
        $rule = $this->rule ?? [];

        return match ($this->recurrence) {
            'yearly' => 'Every year on '.($this->date?->format('M j') ?? 'the same date'),
            'easter' => 'Every year, '.self::easterPhrase((int) ($rule['offset'] ?? 0)),
            'weekday' => 'Every year on the '.self::weekPhrase((int) ($rule['week'] ?? 1)).' '
                .CarbonImmutable::create(2024, 1, 1)->addDays(((int) ($rule['weekday'] ?? 1)) - 1)->format('l')
                .' of '.CarbonImmutable::create(2024, (int) ($rule['month'] ?? 1), 1)->format('F'),
            default => 'Once',
        };
    }

    /** "3 days before Easter", "on Easter Sunday", "the day after Easter". */
    private static function easterPhrase(int $offset): string
    {
        return match (true) {
            $offset === 0 => 'on Easter Sunday',
            $offset === -1 => 'the day before Easter',
            $offset === 1 => 'the day after Easter',
            $offset < 0 => abs($offset).' days before Easter',
            default => $offset.' days after Easter',
        };
    }

    private static function weekPhrase(int $week): string
    {
        return match ($week) {
            self::LAST_WEEK => 'last',
            1 => 'first',
            2 => 'second',
            3 => 'third',
            default => 'fourth',
        };
    }
}
