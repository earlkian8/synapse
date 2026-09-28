<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A single punch event on an {@see AttendanceRecord} (clock in/out or break),
 * carrying its capture context — source, GPS coordinates and an optional selfie —
 * so a mobile DTR app's punches are fully auditable.
 *
 * A punch HR's edit replaces is never erased: it is soft-deleted, so what the
 * day said before survives.
 */
class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * The kinds of punch, in their natural daily order.
     *
     * @var list<string>
     */
    public const TYPES = ['clock_in', 'break_start', 'break_end', 'clock_out'];

    /**
     * Where a punch can be captured — the sources an attendance policy chooses
     * among (ADR 0038). Kiosks and biometric scanners are gone (ADR 0054); a
     * punch they recorded before keeps its `source`, which is history.
     *
     * @var list<string>
     */
    public const CAPTURE_SOURCES = ['web', 'mobile', 'manual'];

    /**
     * Where a punch originated: a capture source, or the end-of-day job closing
     * a forgotten clock-out (ADR 0041), which no policy can switch off.
     *
     * @var list<string>
     */
    public const SOURCES = [...self::CAPTURE_SOURCES, 'system'];

    /**
     * Sources whose punch is checked against a work location's fence: the ones
     * that report where the person was. HR's entry reports nowhere.
     *
     * @var list<string>
     */
    public const LOCATED_SOURCES = ['web', 'mobile'];

    protected $fillable = [
        'organization_id',
        'attendance_record_id',
        'employee_id',
        'type',
        'punched_at',
        'source',
        'latitude',
        'longitude',
        'accuracy',
        'work_location_id',
        'distance_meters',
        'within_geofence',
        'external_id',
        'device_punched_at',
        'received_at',
        'clock_skew_seconds',
        'photo',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'distance_meters' => 'integer',
            'within_geofence' => 'boolean',
            'device_punched_at' => 'datetime',
            'received_at' => 'datetime',
            'clock_skew_seconds' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user who recorded the punch (null when self-punched).
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The site the punch was nearest, when it was checked against one (ADR 0040).
     *
     * @return BelongsTo<WorkLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id')->withTrashed();
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * The selfie as a servable URL: an absolute URL is passed through as-is,
     * otherwise the stored path is resolved on the `public` disk. Null when unset.
     *
     * @return Attribute<string|null, never>
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->photo) {
                    return null;
                }

                return Str::startsWith($this->photo, ['http://', 'https://'])
                    ? $this->photo
                    : Storage::disk('public')->url($this->photo);
            },
        );
    }
}
