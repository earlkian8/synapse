<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan of a QR station's code by somebody of the company (ADR 0103),
 * accepted or refused, and why. It is the record HR reads to see a forwarded
 * code, a faked location or one phone clocking in two people — the attempts
 * the station turned away as well as the punches it let through.
 *
 * Written once and never changed, except to link the punch an accepted scan
 * went on to make.
 */
class AttendanceQrScan extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    public const OUTCOMES = ['accepted', 'refused'];

    /** Where the scan came from: the app, or a phone's browser (number and PIN). */
    public const CLIENTS = ['app', 'browser'];

    /**
     * Why a scan was refused, as the screen and HR read it.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'expired' => 'The code had already changed',
        'invalid' => 'Not a code this station showed',
        'not_showing' => 'The station’s screen wasn’t showing',
        'inactive' => 'The station is switched off',
        'no_site' => 'The station has no site to check against',
        'no_location' => 'The phone sent no location',
        'imprecise' => 'The phone’s location was too rough',
        'mocked' => 'The phone reported a simulated location',
        'away' => 'The phone was away from the station',
        'shared_device' => 'The phone had clocked in somebody else',
        'no_device' => 'The app didn’t say which phone it was',
        'other_network' => 'The phone wasn’t on the station’s network',
        'app_only' => 'Scanned without the app at an app-only station',
        'wrong_pin' => 'The employee number and PIN didn’t match',
        'punch_refused' => 'The punch itself was refused',
    ];

    /**
     * The reasons that say somebody may have tried to get round the station,
     * rather than a code that simply ran out. Written to the activity log too.
     *
     * @var list<string>
     */
    public const SUSPICIOUS = ['invalid', 'not_showing', 'mocked', 'away', 'shared_device', 'other_network'];

    protected $fillable = [
        'organization_id',
        'attendance_qr_station_id',
        'employee_id',
        'client',
        'outcome',
        'reason',
        'punch_type',
        'attendance_punch_id',
        'latitude',
        'longitude',
        'accuracy',
        'distance_meters',
        'mocked',
        'device_hash',
        'ip',
    ];

    protected $hidden = ['device_hash', 'ip'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'distance_meters' => 'integer',
            'mocked' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceQrStation, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(AttendanceQrStation::class, 'attendance_qr_station_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<AttendancePunch, $this>
     */
    public function punch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'attendance_punch_id');
    }

    public function isSuspicious(): bool
    {
        return $this->outcome === 'refused' && in_array($this->reason, self::SUSPICIOUS, true);
    }
}
