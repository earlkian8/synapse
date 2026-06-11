<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\OnboardingProgramFactory;
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
}
