<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * The machine name of the all-powerful role, which bypasses every gate.
     */
    public const SUPER_ADMIN = 'super-admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'label', 'description', 'is_system'];

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
     * Whether this is the protected super-admin role.
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

        $needle = '%'.mb_strtolower($term).'%';

        $query->where(function ($query) use ($needle) {
            foreach (['name', 'label', 'description'] as $column) {
                $query->orWhereRaw('lower('.$column.') like ?', [$needle]);
            }
        });
    }
}
