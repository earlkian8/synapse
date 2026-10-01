<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use BelongsToOrganization;

    /**
     * The three built-in roles every organisation ships with (machine names).
     */
    public const HR_MANAGER = 'hr-manager';

    public const DEPARTMENT_HEAD = 'department-head';

    public const STAFF = 'staff';

    /**
     * The privileged owner role. HR Manager is the top role: it is granted every
     * permission, bypasses every gate, and is protected from editing/deletion.
     */
    public const SUPER_ADMIN = self::HR_MANAGER;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['organization_id', 'name', 'label', 'description', 'is_system'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Permissions granted to this role.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * Users assigned to this role.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Whether this is the privileged owner role (HR Manager) that bypasses every gate.
     */
    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }

    /**
     * Scope a query to a free-text search across name, label and description.
     *
     * @param  Builder<Role>  $query
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
            foreach (['name', 'label', 'description'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
