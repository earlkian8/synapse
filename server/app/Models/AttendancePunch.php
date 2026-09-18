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
     * Where a punch originated: a capture source, or an approved correction
     * request (ADR 0039), which no policy can switch off.
     *
     * @var list<string>
     */
    public const SOURCES = [...self::CAPTURE_SOURCES, 'correction'];

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
