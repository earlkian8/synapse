<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OnboardingTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete checklist item on an {@see OnboardingCase}. Tracks who is responsible,
 * when it is due, and its completion.
 */
class OnboardingTask extends Model
{
    /** @use HasFactory<OnboardingTaskFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * The categories a task may fall under (grouped on the checklist).
     *
     * @var list<string>
     */
    public const CATEGORIES = ['paperwork', 'equipment', 'access', 'orientation', 'training', 'compliance', 'other'];

    /**
     * The states a task may be in. `done`/`skipped` are resolved (count as progress).
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'in_progress', 'done', 'skipped'];

    protected $fillable = [
        'organization_id',
        'onboarding_case_id',
        'title',
        'description',
        'category',
        'assigned_to',
        'due_date',
        'status',
        'completed_at',
        'completed_by',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<OnboardingCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    /**
     * The user responsible for completing this task.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /**
     * The user who marked this task done.
     *
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the task has been resolved (done or deliberately skipped).
     */
    public function isResolved(): bool
    {
        return in_array($this->status, ['done', 'skipped'], true);
    }

    /**
     * Whether the task is past due and still unresolved.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isResolved()
            && $this->due_date->isPast();
    }
}
