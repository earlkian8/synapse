<?php

namespace App\Models;

use App\Support\Admin\AdminSession;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Somebody who runs SYNAPSE itself, signing in to the admin portal (ADR 0085).
 *
 * Deliberately not a {@see User}: an admin belongs to no organisation, holds no ERP
 * role, and is authenticated by its own guard (`admin`), so neither kind of
 * identity can ever stand in for the other. Two roles: a super admin also manages
 * the admin accounts and reads the admin activity log; an admin monitors.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'must_change_password', 'created_by'])]
#[Hidden(['password', 'two_factor_secret', 'remember_token'])]
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    /**
     * Each role and how the portal names it.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        self::ROLE_SUPER_ADMIN => 'Super admin',
        self::ROLE_ADMIN => 'Admin',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => self::ROLE_ADMIN,
        'is_active' => true,
        'must_change_password' => true,
    ];

    /**
     * The TOTP secret and the sign-in stamps are absent from {@see $fillable}: they
     * change only through enrolment and {@see AdminSession}, never a form.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Emails are stored lower-cased, so `Ana@x.ph` and `ana@x.ph` are one account.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * The admin who created this account, if it was not made at the console.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Whether an authenticator is enrolled (a secret confirmed by a good code).
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /**
     * End this admin's portal sessions — all of them, or all but one.
     */
    public function revokeSessions(?int $exceptTokenId = null): int
    {
        return $this->tokens()
            ->when($exceptTokenId !== null, fn ($query) => $query->whereKeyNot($exceptTokenId))
            ->delete();
    }

    /**
     * How many super admins can still sign in. The portal never lets this reach
     * zero, or nobody could manage the admin accounts again.
     */
    public static function activeSuperAdminCount(): int
    {
        return self::query()
            ->where('role', self::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Scope to a free-text search over name and email.
     *
     * @param  Builder<Admin>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(fn (Builder $query) => $query
            ->where('name', $like, $needle)
            ->orWhere('email', $like, $needle));
    }
}
