<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'parent_id',
        'head_id',
        'description',
    ];

    /**
     * The parent department, if this is a sub-department.
     *
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * Direct sub-departments.
     *
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * The employee who heads this department.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_id');
    }

    /**
     * Positions defined under this department.
     *
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Employees assigned to this department.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across name and code.
     *
     * @param  Builder<Department>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';

        $query->where(function (Builder $query) use ($needle) {
            foreach (['name', 'code'] as $column) {
                $query->orWhereRaw('lower('.$column.') like ?', [$needle]);
            }
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The ids of this department and every department beneath it — the set a
     * department may **not** be re-parented into (it would create a cycle).
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = Department::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
