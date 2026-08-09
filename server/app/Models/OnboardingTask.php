<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OnboardingTaskFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The states that count as progress — a task is off the checklist either way.
     *
     * @var list<string>
     */
    public const RESOLVED_STATUSES = ['done', 'skipped'];

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
        return in_array($this->status, self::RESOLVED_STATUSES, true);
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

    /**
     * Set the task's status, stamping (or clearing) who finished it and when. The
     * one place completion is recorded, shared by the checklist and the assistant.
     */
    public function markStatus(string $status, ?int $completedBy = null): void
    {
        $done = $status === 'done';

        $this->update([
            'status' => $status,
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $completedBy : null,
        ]);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Tasks still outstanding (neither done nor deliberately skipped).
     *
     * @param  Builder<OnboardingTask>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNotIn('status', self::RESOLVED_STATUSES);
    }

    /**
     * Outstanding tasks whose due date has passed.
     *
     * @param  Builder<OnboardingTask>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->unresolved()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    /**
     * Tasks belonging to a case that is still in flight — the only ones anyone
     * can actually be chased about.
     *
     * @param  Builder<OnboardingTask>  $query
     */
    public function scopeOnActiveCase(Builder $query): void
    {
        $query->whereHas('case', fn (Builder $case) => $case->active());
    }

    /**
     * Free-text search across a task's title and description.
     *
     * @param  Builder<OnboardingTask>  $query
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
            foreach (['title', 'description'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
