<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\OnboardingProgramFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable onboarding template: a named checklist of blueprint tasks, optionally
 * targeted at a department and/or employment type. A hire instantiates the
 * best-matching active program into an {@see OnboardingCase}.
 */
class OnboardingProgram extends Model
{
    /** @use HasFactory<OnboardingProgramFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'department_id',
        'employment_type',
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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The blueprint tasks, in display order.
     *
     * @return HasMany<OnboardingProgramTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingProgramTask::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<OnboardingCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(OnboardingCase::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Keep at most one default program per tenant: when this one is the default,
     * every other one stops being it.
     */
    public function enforceSingleDefault(): void
    {
        if (! $this->is_default) {
            return;
        }

        static::whereKeyNot($this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Replace the program's blueprint tasks wholesale — they carry no history, and
     * the editor always sends the full list. The one writer, so the setup screen
     * and the assistant produce identical templates.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function syncBlueprint(array $tasks): void
    {
        $this->tasks()->delete();

        foreach (array_values($tasks) as $index => $task) {
            $this->tasks()->create([
                'title' => $task['title'],
                'description' => $task['description'] ?? null,
                'category' => $task['category'],
                'due_offset_days' => $task['due_offset_days'],
                'sort_order' => $index,
            ]);
        }
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across a program's name and description.
     *
     * @param  Builder<OnboardingProgram>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $query) use ($needle, $like) {
            foreach (['name', 'description'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
