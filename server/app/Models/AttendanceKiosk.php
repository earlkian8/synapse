<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A shared device at a site where people who have no phone clock in (ADR 0092).
 *
 * Nobody signs in on it. It is **paired** instead: HR asks for a pairing code,
 * which works once and only for {@see PAIRING_MINUTES}, and types it on the
 * device. Pairing hands the device a secret of its own, kept in an encrypted,
 * HttpOnly cookie, and only that secret's SHA-256 is stored here — as for the
 * code. Neither can be read back, and neither is ever shown again.
 *
 * Pairing again (a replaced tablet) or signing the device out ends the secret
 * the device held. Removing a kiosk does both and keeps the row, archived, so
 * the punches made at it still name it.
 */
class AttendanceKiosk extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** How long a pairing code works for. */
    public const PAIRING_MINUTES = 15;

    /** How many characters a pairing code has. */
    public const CODE_LENGTH = 8;

    /**
     * The characters a pairing code is made of — Crockford's base 32, which has
     * no I, L, O or U, so nothing on the screen can be misread as something else.
     */
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** How recently a device must have been heard from to count as on line. */
    public const ONLINE_MINUTES = 3;

    protected $fillable = [
        'organization_id',
        'name',
        'work_location_id',
        'created_by',
    ];

    protected $hidden = ['device_token_hash', 'pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'pairing_expires_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The site the kiosk stands at. A punch made at it is placed there.
     *
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── State ────────────────────────────────────────────────────────────────

    /**
     * `paired` — a device holds its secret; `pairing` — a code is waiting to be
     * typed; `unpaired` — neither.
     */
    public function status(): string
    {
        return match (true) {
            $this->device_token_hash !== null => 'paired',
            $this->hasLivePairingCode() => 'pairing',
            default => 'unpaired',
        };
    }

    public function hasLivePairingCode(): bool
    {
        return $this->pairing_code_hash !== null
            && $this->pairing_expires_at !== null
            && $this->pairing_expires_at->isFuture();
    }

    /** Whether the paired device has been heard from in the last few minutes. */
    public function isOnline(): bool
    {
        return $this->device_token_hash !== null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES));
    }

    /**
     * The clock the kiosk shows and judges "today" on: its site's, or the
     * organisation's (ADR 0079).
     */
    public function clockZone(): string
    {
        return $this->location?->clockZone() ?? OrganizationClock::timezone();
    }

    // ── Pairing ──────────────────────────────────────────────────────────────

    /**
     * Give the kiosk a new pairing code and return it — the only time it exists
     * in the clear. A code waiting before it stops working. Does not save.
     */
    public function issuePairingCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        $this->forceFill([
            'pairing_code_hash' => self::hash($code),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_MINUTES),
        ]);

        return $code;
    }

    /**
     * Hand the device that typed the code its secret, ending whatever device
     * held one before, and spend the code. Returns the secret. Saves.
     */
    public function pair(?string $deviceLabel): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'device_token_hash' => self::hash($token),
            'device_label' => $deviceLabel !== null ? Str::limit($deviceLabel, 117) : null,
            'paired_at' => now(),
            'last_seen_at' => now(),
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
        ])->save();

        return $token;
    }

    /**
     * End the device's secret, and any code waiting. Does not save.
     */
    public function unpair(): void
    {
        $this->forceFill([
            'device_token_hash' => null,
            'device_label' => null,
            'paired_at' => null,
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
        ]);
    }

    /**
     * The kiosk a pairing code is waiting on, across every organisation — the
     * code is what says which company it is. Null for a code that is wrong,
     * spent or out of time, or whose kiosk was removed.
     */
    public static function findByPairingCode(?string $code): ?self
    {
        $code = self::normaliseCode((string) $code);

        if (strlen($code) !== self::CODE_LENGTH) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('pairing_code_hash', self::hash($code))
            ->where('pairing_expires_at', '>', now())
            ->first();
    }

    /**
     * The kiosk a device's secret belongs to, across every organisation. Null
     * for an unknown secret, or a kiosk signed out or removed since.
     */
    public static function findByDeviceToken(?string $token): ?self
    {
        $token = (string) $token;

        if (strlen($token) !== 64) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('device_token_hash', self::hash($token))
            ->first();
    }

    /**
     * A code as somebody might type it — lower case, with the dash it is shown
     * with, an O for a zero or an I or L for a one — in the shape it was issued.
     */
    public static function normaliseCode(string $code): string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * Both secrets are long and random, so a plain SHA-256 is enough to keep
     * them from being read back, and finds the kiosk in one query.
     */
    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
