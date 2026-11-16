<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\QrCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A QR station (ADR 0103): a screen at a site showing a code that changes every
 * {@see $rotate_seconds} seconds, which people scan with their own phone to
 * clock in.
 *
 * The code is signed with the station's {@see $secret}, which is encrypted at
 * rest and never leaves the server: the screen asks for each code as it is
 * due, so nobody holding the page can work out the next one. Resetting the
 * secret ends every code shown before at once.
 *
 * Removing a station keeps the row, archived, so the punches scanned at it still
 * name it.
 */
class AttendanceQrStation extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** How often a station's code may change, in seconds. */
    public const ROTATE_CHOICES = [10, 15, 30, 60];

    public const DEFAULT_ROTATE_SECONDS = 15;

    /**
     * How people may scan the code: `app` — only with the SYNAPSE app, which
     * vouches for the phone and its location; `any` — with any phone's camera
     * too, signing in with their employee number and PIN in the browser.
     */
    public const SCAN_MODES = ['app', 'any'];

    /**
     * How recently the screen must have asked for codes for the station to
     * count as showing — a little over the time between its asks
     * ({@see QrCodes::REFRESH_SECONDS}).
     */
    public const SHOWING_SECONDS = QrCodes::REFRESH_SECONDS + 45;

    protected $fillable = [
        'organization_id',
        'name',
        'work_location_id',
        'rotate_seconds',
        'scan_mode',
        'same_network',
        'is_active',
        'created_by',
    ];

    protected $hidden = ['secret', 'last_shown_ip'];

    protected $attributes = [
        'rotate_seconds' => self::DEFAULT_ROTATE_SECONDS,
        'scan_mode' => 'app',
        'same_network' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'rotate_seconds' => 'integer',
            'is_active' => 'boolean',
            'same_network' => 'boolean',
            'last_shown_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AttendanceQrStation $station): void {
            if (blank($station->secret)) {
                $station->secret = self::newSecret();
            }
        });
    }

    public static function newSecret(): string
    {
        return Str::random(64);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The site the station's screen stands at. A scan is accepted only from a
     * phone inside its fence.
     *
     * @return BelongsTo<WorkLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id')->withTrashed();
    }

    /**
     * @return HasMany<AttendanceQrScan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(AttendanceQrScan::class);
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who last had the station's code on their screen.
     *
     * @return BelongsTo<User, $this>
     */
    public function shownBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_shown_by');
    }

    // ── State ────────────────────────────────────────────────────────────────

    /**
     * Whether a screen is showing the station's code right now.
     */
    public function isShowing(): bool
    {
        return $this->last_shown_at !== null
            && $this->last_shown_at->gt(now()->subSeconds(self::SHOWING_SECONDS));
    }

    /**
     * Whether people may scan it with any phone's camera, signing in in the
     * browser, rather than only with the app.
     */
    public function allowsBrowser(): bool
    {
        return $this->scan_mode === 'any';
    }

    /**
     * Whether a scan of it can be accepted at all: switched on, and standing at a
     * site that still exists — there is nothing to check a phone against
     * otherwise.
     */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->location !== null
            && ! $this->location->trashed()
            && (bool) $this->location->is_active;
    }
}
