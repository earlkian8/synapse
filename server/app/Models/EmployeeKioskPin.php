<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Attendance\KioskPins;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The PIN somebody clocks in with at a kiosk (ADR 0092), with their employee
 * number. Stored as a password hash; read through {@see KioskPins}.
 *
 * HR issues one and sees it once. It `must_change` until its holder has chosen
 * their own at a kiosk, so the PIN that clocks somebody in is one HR never knew.
 * Wrong tries are counted here rather than in a cache, so a lock survives a
 * restart and HR can see it.
 */
class EmployeeKioskPin extends Model
{
    use BelongsToOrganization;

    /** How many digits a PIN has. */
    public const LENGTH = 6;

    /** Wrong tries in a row before the PIN locks. */
    public const MAX_ATTEMPTS = 5;

    /** How long a locked PIN stays locked. */
    public const LOCK_MINUTES = 15;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'issued_by',
    ];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'must_change' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'issued_at' => 'datetime',
            'changed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * `locked` — too many wrong tries; `new` — still the PIN HR issued;
     * `active` — its holder's own.
     */
    public function status(): string
    {
        return match (true) {
            $this->isLocked() => 'locked',
            $this->must_change => 'new',
            default => 'active',
        };
    }
}
