<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
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
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable([
    'organization_id',
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
    use BelongsToOrganization, HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Memoised set of the user's effective permission names.
     */
    private ?Collection $cachedPermissionNames = null;

    /**
     * Roles assigned to this user.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
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
