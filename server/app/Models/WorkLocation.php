<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\FenceGeometry;
use App\Support\Attendance\GeofenceCheck;
use App\Support\Attendance\PolicyResolver;
use App\Support\Attendance\ShiftResolver;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A place a company's people work from (ADR 0040): a point and a radius, or a
 * shape drawn corner by corner (ADR 0079) — the fence a punch is checked against
 * ({@see GeofenceCheck}) — and, optionally, the schedule and attendance policy
 * the people based there default to, and the clock they keep. Those two
 * sit between the department and the organisation in the precedence chains
 * ({@see ShiftResolver}, {@see PolicyResolver}), read from somebody's primary
 * location.
 *
 * Archived rather than hard-deleted, like the other Company Setup catalogues,
 * so a punch that names one still says where it was.
 */
class WorkLocation extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** How wide a fence may be drawn, in metres. */
    public const MIN_RADIUS_METERS = 25;

    public const MAX_RADIUS_METERS = 5000;

    /** A fence is a circle, or a shape drawn corner by corner (ADR 0079). */
    public const FENCE_TYPES = ['circle', 'polygon'];

    /** The most corners a shape may have. */
    public const MAX_POLYGON_POINTS = 50;

    protected $fillable = [
        'organization_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'fence_type',
        'polygon',
        'timezone',
        'default_work_schedule_id',
        'attendance_policy_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'polygon' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The people based here, with which of their sites is primary.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_work_locations')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function defaultSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'default_work_schedule_id')->withTrashed();
    }

    /**
     * @return BelongsTo<AttendancePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'attendance_policy_id')->withTrashed();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  Builder<WorkLocation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The sites a person's punch is checked against: the active ones they are
     * based at, or — when nobody has said where they are based — every active
     * site the company has, so "on site" means at any of them.
     *
     * @return Collection<int, WorkLocation>
     */
    public static function fenceFor(Employee $employee): Collection
    {
        $own = static::query()
            ->active()
            ->whereHas('employees', fn (Builder $query) => $query->whereKey($employee->getKey()))
            ->get();

        return $own->isNotEmpty() ? $own : static::query()->active()->get();
    }

    /**
     * Each person's base, for the precedence chains: the active site marked
     * primary, or — when they are based at exactly one — that one. Somebody
     * based at several with none marked primary has no base to default from. One
     * query for the whole roster.
     *
     * @param  SupportCollection<int, Employee>  $employees
     * @return array<int, WorkLocation> Keyed by employee id.
     */
    public static function primaryFor(SupportCollection $employees): array
    {
        $ids = $employees->pluck('id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = static::query()
            ->active()
            ->join('employee_work_locations', 'employee_work_locations.work_location_id', '=', 'work_locations.id')
            ->whereIn('employee_work_locations.employee_id', $ids)
            ->get([
                'work_locations.id',
                'work_locations.name',
                'work_locations.default_work_schedule_id',
                'work_locations.attendance_policy_id',
                'work_locations.timezone',
                'employee_work_locations.employee_id as based_employee_id',
                'employee_work_locations.is_primary as based_primary',
            ])
            ->groupBy('based_employee_id');

        $out = [];

        foreach ($rows as $employeeId => $sites) {
            $base = $sites->first(fn (WorkLocation $site): bool => (bool) $site->based_primary)
                ?? ($sites->count() === 1 ? $sites->first() : null);

            if ($base !== null) {
                $out[(int) $employeeId] = $base;
            }
        }

        return $out;
    }

    /**
     * The fence in words: "150 m circle", "Shape · 6 corners · 1.2 ha".
     */
    public function fenceLabel(): string
    {
        $shape = $this->shape();

        if ($shape === null) {
            return "{$this->radius_meters} m circle";
        }

        $area = FenceGeometry::areaSquareMeters($shape);
        $size = $area >= 10000
            ? number_format($area / 10000, 1).' ha'
            : number_format($area).' m²';

        return 'Shape · '.count($shape).' corners · '.$size;
    }

    /**
     * The clock the site keeps: its own zone, or the organisation's.
     */
    public function clockZone(): string
    {
        return filled($this->timezone) ? (string) $this->timezone : OrganizationClock::timezone();
    }

    /**
     * The fence's corners when it is drawn as a shape, or null for a circle.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public function shape(): ?array
    {
        if ($this->fence_type !== 'polygon' || ! is_array($this->polygon) || count($this->polygon) < 3) {
            return null;
        }

        return array_values(array_map(
            fn (array $corner): array => [(float) $corner[0], (float) $corner[1]],
            $this->polygon,
        ));
    }

    /**
     * The zone a person's base site keeps, or null when it keeps the
     * organisation's — or they have no base (ADR 0079).
     */
    public static function timezoneFor(Employee|int $employee): ?string
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;
        $zone = (static::primaryFor(collect([['id' => $id]]))[$id] ?? null)?->timezone;

        return filled($zone) ? (string) $zone : null;
    }

    /**
     * Make this the primary site of each of the given people, and no other site
     * theirs — one primary per person.
     *
     * @param  list<int>  $employeeIds
     */
    public function makePrimaryFor(array $employeeIds): void
    {
        if ($employeeIds === []) {
            return;
        }

        $this->getConnection()->table('employee_work_locations')
            ->whereIn('employee_id', $employeeIds)
            ->update(['is_primary' => false]);

        $this->getConnection()->table('employee_work_locations')
            ->where('work_location_id', $this->id)
            ->whereIn('employee_id', $employeeIds)
            ->update(['is_primary' => true]);
    }
}
