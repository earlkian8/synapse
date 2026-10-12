<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable clearance template: a named list of blueprint sign-off items,
 * optionally targeted at a department and/or exit type. Starting an offboarding
 * instantiates the best-matching (or explicitly chosen) active program into the
 * case's clearance checklist — the exit-side mirror of {@see OnboardingProgram}.
 */
class OffboardingProgram extends Model
{
    use BelongsToOrganization, HasHashid;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'department_id',
        'exit_type',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The department of employees this template targets (not the sign-off owner).
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The blueprint sign-off items, in display order.
     *
     * @return HasMany<OffboardingProgramItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OffboardingProgramItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<OffboardingCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(OffboardingCase::class);
    }

    /**
     * Active clearance templates as a picker offers them — starting an exit,
     * accepting a resignation, adding a template's items. Default first, then
     * alphabetical.
     *
     * @return list<array{id: int, name: string, is_default: bool, items_count: int}>
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn (OffboardingProgram $program): array => [
                'id' => $program->id,
                'name' => $program->name,
                'is_default' => (bool) $program->is_default,
                'items_count' => (int) $program->items_count,
            ])
            ->all();
    }
}
