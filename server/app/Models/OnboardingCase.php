<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\OnboardingCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's onboarding journey — a checklist of {@see OnboardingTask}s with a
 * lifecycle (pending → in_progress → completed / cancelled). Usually created by the
 * recruitment hire bridge, but can be started manually for any employee. See ADR 0007.
 */
class OnboardingCase extends Model
{
    /** @use HasFactory<OnboardingCaseFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    /**
     * The lifecycle states a case moves through.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'onboarding_program_id',
        'status',
        'start_date',
        'target_end_date',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'target_end_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The program this case was instantiated from, if any.
     *
     * @return BelongsTo<OnboardingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(OnboardingProgram::class, 'onboarding_program_id');
    }

    /**
     * The checklist tasks, in display order.
     *
     * @return HasMany<OnboardingTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class)->orderBy('sort_order')->orderBy('id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the case is still being worked on.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'in_progress'], true);
    }
}
