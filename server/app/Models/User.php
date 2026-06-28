<?php

namespace App\Models;

use App\Support\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable([
    'first_name',
    'middle_name',
    'last_name',
    'suffix',
    'email',
    'password',
    'phone_number',
    'profile_photo',
    'employee_id',
    'is_active',
    'email_notifications',
    'push_notifications',
    'last_login_at',
    'password_changed_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Memoised set of the user's effective permission names.
     */
    private ?Collection $cachedPermissionNames = null;

    /**
     * Roles assigned to this user.
     *
     * Roles are organisation-scoped, so when a tenant is bound this relation only
     * surfaces the roles the user holds in the *active* organisation (ADR 0023).
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * The organisations this identity belongs to (its memberships). A user is a
     * member of an organisation iff a row exists here; `is_default` marks the org a
     * fresh login lands in. See ADR 0023.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['is_default', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Whether this identity may act in the given organisation.
     */
    public function isMemberOf(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->memberships()->where('organizations.id', $id)->exists();
    }

    /**
     * The organisation a fresh login should land in: the one flagged default, else
     * the earliest membership. Null when the identity has no memberships yet.
     */
    public function defaultOrganization(): ?Organization
    {
        return $this->memberships()
            ->orderByDesc('organization_user.is_default')
            ->orderBy('organization_user.id')
            ->first();
    }

    /**
     * Scope to identities that are members of the given organisation.
     *
     * @param  Builder<User>  $query
     */
    public function scopeInOrganization(Builder $query, Organization|int $organization): void
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        $query->whereHas('memberships', fn (Builder $q) => $q->where('organizations.id', $id));
    }

    /**
     * Scope to members of the currently-bound tenant. A no-op when none is bound
     * (console/login), mirroring how tenant-owned models behave.
     *
     * @param  Builder<User>  $query
     */
    public function scopeInCurrentOrganization(Builder $query): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            $query->inOrganization($tenancy->id());
        }
    }

    /**
     * Constrain implicit route-model binding to members of the active tenant.
     *
     * Users are global identities (ADR 0023), so without this an admin could reach
     * an account from another organisation by id. Wraps the default query (so
     * `withTrashed()` route bindings still work) with a membership filter.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            $query->whereHas('memberships', fn (Builder $q) => $q->where('organizations.id', $tenancy->id()));
        }

        return $query;
    }

    /**
     * The employee (HR) record linked to this account, if any.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Whether the user holds the all-powerful super-admin role.
     */
    public function isSuperAdmin(): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('name', Role::SUPER_ADMIN);
    }

    /**
     * Whether the user is assigned the given role (by machine name).
     */
    public function hasRole(string $role): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('name', $role);
    }

    /**
     * Whether the user can perform the given permission. Super admins always can.
     */
    public function hasPermissionTo(string $permission): bool
    {
        return $this->isSuperAdmin() || $this->permissionNames()->contains($permission);
    }

    /**
     * The distinct permission names granted to the user through their roles.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        if ($this->cachedPermissionNames !== null) {
            return $this->cachedPermissionNames;
        }

        $this->loadMissing('roles.permissions');

        return $this->cachedPermissionNames = $this->roles
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    /**
     * Forget any memoised permissions (call after changing role assignments).
     */
    public function forgetCachedPermissions(): void
    {
        $this->cachedPermissionNames = null;
        $this->unsetRelation('roles');
    }

    /**
     * Scope a query to apply a free-text search across the searchable columns.
     *
     * @param  Builder<User>  $query
     */
    public function scopeSearch($query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function ($query) use ($needle, $like) {
            foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'employee_id', 'phone_number'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }

    /**
     * The accessors to append to the model's serialized form.
     *
     * @var list<string>
     */
    protected $appends = ['full_name', 'avatar'];

    /**
     * Get the public URL of the user's profile photo, if any.
     *
     * Exposed as `avatar` so shared auth props render the photo in the header.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile_photo
                ? Storage::disk('public')->url($this->profile_photo)
                : null,
        );
    }

    /**
     * Get the user's full name, combining their name parts and suffix.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim(implode(' ', array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
                $this->suffix,
            ]))),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }
}
