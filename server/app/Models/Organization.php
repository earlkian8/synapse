<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * Users (accounts) that belong to this organisation.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
