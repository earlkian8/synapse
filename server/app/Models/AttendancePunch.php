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
 * A punch is never erased by a correction (ADR 0039): it is soft-deleted, and one
 * an approved correction replaced names that request (`replaced_by_request_id`),
 * while the punch it wrote in its place names it too (`attendance_request_id`,
 * `source = correction`). The trail of who asked, and what the day said before,
 * survives.
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
     * among (ADR 0038).
     *
     * @var list<string>
     */
    public const CAPTURE_SOURCES = ['web', 'mobile', 'kiosk', 'biometric', 'manual'];

    /**
     * Where a punch originated: a capture source, an approved correction
     * request (ADR 0039), or the end-of-day job closing a forgotten clock-out
     * (ADR 0041) — neither of which any policy can switch off.
     *
     * @var list<string>
     */
    public const SOURCES = [...self::CAPTURE_SOURCES, 'correction', 'system'];

    /**
     * Sources where a person is punching for themselves, so the punch engine
     * enforces the policy's capture rules and the day's order on them. A device's
     * punch is recorded as it happened instead (ADR 0040).
     *
     * @var list<string>
     */
    public const PERSON_SOURCES = ['web', 'mobile', 'kiosk', 'manual'];

    /**
     * Sources whose punch is checked against a work location's fence: the ones
     * that report where the person was. A kiosk or scanner is where it is.
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
        'attendance_device_id',
        'external_id',
        'device_punched_at',
        'received_at',
        'clock_skew_seconds',
        'photo',
        'note',
        'recorded_by',
        'attendance_request_id',
        'replaced_by_request_id',
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

    /**
     * The kiosk or scanner that sent the punch, when one did.
     *
     * @return BelongsTo<AttendanceDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id')->withTrashed();
    }

    /**
     * The correction request that wrote this punch, when one did.
     *
     * @return BelongsTo<AttendanceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AttendanceRequest::class, 'attendance_request_id');
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
