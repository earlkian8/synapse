<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\JoinCode;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A tenant: one organisation (company) per registration.
 *
 * Every business record in the system belongs to exactly one organisation and is
 * isolated from the others by a global query scope (see
 * {@see BelongsToOrganization}). The organisation also doubles
 * as the company profile — there is one per installation tenant, not a separate
 * `company_profiles` singleton (see ADR 0005).
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'join_code_enabled',
        'legal_name',
        'logo',
        'email',
        'phone',
        'address',
        'tin',
        'sss_employer_no',
        'philhealth_employer_no',
        'pagibig_employer_no',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['logo_url'];

    /**
     * Mirrors the column default so a freshly-made instance answers the same as one
     * read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['join_code_enabled' => true];

    /**
     * `join_code` is deliberately absent from {@see $fillable}: it is a credential,
     * not a profile field, and only ever changes through {@see rotateJoinCode()}.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'join_code_enabled' => 'boolean',
        ];
    }

    /**
     * Identities that are members of this organisation (ADR 0023). A user belongs
     * to many organisations through the `organization_user` pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['is_default', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Roles defined within this organisation.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Employees (HR records) belonging to this organisation.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Departments belonging to this organisation.
     *
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * People waiting at the door — identities that typed this organisation's join
     * code but need HR to place them on the roster (ADR 0026).
     *
     * @return HasMany<OrganizationJoinRequest, $this>
     */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(OrganizationJoinRequest::class);
    }

    /**
     * Claim tickets issued against this organisation's roster lines.
     *
     * @return HasMany<EmployeeInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class);
    }

    /**
     * Issue a fresh join code, invalidating the current one. Used both to seed a
     * brand-new organisation and to cut off a code that has leaked.
     */
    public function rotateJoinCode(): string
    {
        $code = JoinCode::uniqueFor(self::class, 'join_code', JoinCode::ORGANIZATION_LENGTH);

        $this->forceFill(['join_code' => $code])->save();

        return $code;
    }

    /**
     * Resolve an organisation from a typed join code, or null when it matches
     * nothing or its owner has switched code entry off.
     *
     * Deliberately unscoped: the code is answered from *outside* any tenant, by
     * somebody who is not yet a member of the organisation they are naming.
     */
    public static function findByJoinCode(?string $code): ?self
    {
        $normalized = JoinCode::normalize($code);

        if ($normalized === '') {
            return null;
        }

        return self::query()
            ->where('join_code', $normalized)
            ->where('join_code_enabled', true)
            ->first();
    }

    /**
     * The public URL of the organisation's logo, if any.
     *
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->logo
                ? Storage::disk('public')->url($this->logo)
                : null,
        );
    }

    /**
     * The organisation's initials, for avatar fallbacks.
     */
    public function initials(): string
    {
        return mb_strtoupper(mb_substr((string) $this->name, 0, 2));
    }
}
