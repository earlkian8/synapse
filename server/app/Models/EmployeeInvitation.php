<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\EmployeeInvitations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * A claim ticket for one roster line: HR's invitation for a specific
 * {@see Employee} to be occupied by whichever identity holds this secret (ADR 0026).
 *
 * The secret has two forms of the same grant — `token` (the sha256 of the emailed
 * link, so the plain token exists only in the mail) and `code` (short enough to
 * retype into the mobile app). Redeeming either does the same thing.
 *
 * Nothing here sweeps expired rows; `expires_at` is *evaluated* on every lookup, so
 * an invitation stops working the moment it lapses whether or not anything ran.
 *
 * All of this is driven through {@see EmployeeInvitations} — do not issue, redeem,
 * or revoke one by touching this model directly.
 */
class EmployeeInvitation extends Model
{
    use BelongsToOrganization, Notifiable;

    /**
     * Mail is addressed to the invitation itself: there is frequently no account
     * behind `email` yet, which is exactly what the invitation exists to fix.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    protected $fillable = [
        'organization_id',
        'employee_id',
        'email',
        'token',
        'code',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * The HR user who issued the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The identity that claimed it, once accepted.
     *
     * @return BelongsTo<User, $this>
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    // ── State ────────────────────────────────────────────────────────────────

    /**
     * Where this invitation stands: `accepted`, `revoked`, `expired`, or `pending`.
     * Derived, never stored — expiry has no writer to keep a column honest.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Whether this invitation can still be redeemed right now.
     */
    public function isRedeemable(): bool
    {
        return $this->status() === 'pending';
    }

    /**
     * Scope to invitations that are still outstanding — neither accepted, nor
     * revoked, nor lapsed.
     *
     * @param  Builder<EmployeeInvitation>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
