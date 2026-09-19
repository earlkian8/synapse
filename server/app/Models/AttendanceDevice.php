<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Attendance\DevicePunchIngestor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A kiosk or a biometric scanner that sends punches (ADR 0040). It is
 * authenticated by a key rather than a user: the key is shown once when the
 * device is registered (or its key is replaced) and only its SHA-256 is kept.
 *
 * A device's punches are what happened at it, so they are recorded rather than
 * judged — see {@see DevicePunchIngestor}.
 */
class AttendanceDevice extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** @var list<string> */
    public const TYPES = ['kiosk', 'biometric'];

    /** What every key starts with, so one pasted in the wrong place is recognisable. */
    public const KEY_PREFIX = 'sdk_';

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'work_location_id',
        'is_active',
        'csv_mapping',
        'created_by',
    ];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'csv_mapping' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<WorkLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id')->withTrashed();
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    // ── Keys ─────────────────────────────────────────────────────────────────

    /**
     * Give the device a new key, keep its hash, and return the key — the only
     * time it exists in the clear. Does not save.
     */
    public function issueKey(): string
    {
        $key = self::KEY_PREFIX.Str::random(40);

        $this->forceFill([
            'api_key_hash' => self::hashKey($key),
            'api_key_hint' => substr($key, -4),
        ]);

        return $key;
    }

    /**
     * The active device a key belongs to, across every organisation — the key
     * is what says which tenant it is. Null for an unknown, archived or
     * deactivated device.
     */
    public static function findByKey(?string $key): ?self
    {
        $key = trim((string) $key);

        if ($key === '' || ! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('api_key_hash', self::hashKey($key))
            ->first();
    }

    /**
     * Keys are 40 random characters, so a plain SHA-256 is enough to keep them
     * from being read back, and lets a device be found by its key in one query.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * The source a punch from this device is recorded with.
     */
    public function punchSource(): string
    {
        return $this->type === 'kiosk' ? 'kiosk' : 'biometric';
    }
}
