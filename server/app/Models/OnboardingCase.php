<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\OnboardingCaseFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The states in which onboarding is still being worked on.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['pending', 'in_progress'];

    /**
     * The deliberate lifecycle moves a case may be put through.
     *
     * @var list<string>
     */
    public const LIFECYCLE_ACTIONS = ['complete', 'cancel', 'reopen'];

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
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Put the case through a lifecycle move. The one place completion is stamped
     * (and un-stamped), so the board, the API and the assistant can never drift.
     *
     * @param  'complete'|'cancel'|'reopen'  $action
     */
    public function applyLifecycle(string $action): void
    {
        match ($action) {
            'complete' => $this->update(['status' => 'completed', 'completed_at' => now()]),
            'cancel' => $this->update(['status' => 'cancelled', 'completed_at' => null]),
            'reopen' => $this->update(['status' => 'in_progress', 'completed_at' => null]),
        };
    }

    /**
     * Nudge a `pending` case into `in_progress` once any work has happened, so the
     * board reflects activity without forcing a manual status change.
     */
    public function touchProgress(): void
    {
        if ($this->status !== 'pending') {
            return;
        }

        $hasActivity = $this->tasks()
            ->whereIn('status', [...OnboardingTask::RESOLVED_STATUSES, 'in_progress'])
            ->exists();

        if ($hasActivity) {
            $this->update(['status' => 'in_progress']);
        }
    }

    /**
     * The case's progress summary — done / resolved / overdue out of total, plus
     * the derived percentage. Derived from loaded tasks when present, else from the
     * `*_count` aggregates the index query adds, else by counting in the database.
     * The single definition of "how far along is this onboarding", shared by the
     * board resource and the assistant.
     *
     * @return array{total: int, done: int, resolved: int, overdue: int, percent: int}
     */
    public function progressSummary(): array
    {
        if ($this->relationLoaded('tasks')) {
            $tasks = $this->tasks;
            $total = $tasks->count();
            $done = $tasks->where('status', 'done')->count();
            $resolved = $tasks->whereIn('status', OnboardingTask::RESOLVED_STATUSES)->count();
            $overdue = $tasks->filter->isOverdue()->count();
        } elseif ($this->tasks_count !== null) {
            $total = (int) $this->tasks_count;
            $done = (int) ($this->done_tasks_count ?? 0);
            $resolved = (int) ($this->resolved_tasks_count ?? 0);
            $overdue = (int) ($this->overdue_tasks_count ?? 0);
        } else {
            $total = $this->tasks()->count();
            $done = $this->tasks()->where('status', 'done')->count();
            $resolved = $this->tasks()->whereIn('status', OnboardingTask::RESOLVED_STATUSES)->count();
            $overdue = $this->tasks()->overdue()->count();
        }

        return [
            'total' => $total,
            'done' => $done,
            'resolved' => $resolved,
            'overdue' => $overdue,
            'percent' => $total > 0 ? (int) round($resolved / $total * 100) : 0,
        ];
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Cases still in flight.
     *
     * @param  Builder<OnboardingCase>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
