<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\GoalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something one employee commits to in a review cycle (ADR 0073), measured from
 * a start value to a target — progress to 100 %, or a number in a unit ("40
 * deals", "reduce defects from 40 to 10") — and checked in on through the cycle
 * ({@see GoalCheckIn}). `health` is what the latest check-in said; the status
 * runs active → achieved | missed | dropped. Every change is
 * {@see GoalWorkflow}.
 */
class PerformanceGoal extends Model
{
    use BelongsToOrganization, HasHashid;

    public const MEASURES = ['percent', 'number'];

    public const STATUSES = ['active', 'achieved', 'missed', 'dropped'];

    public const HEALTHS = ['on_track', 'at_risk', 'off_track'];

    /** Days without a check-in after which an active goal reads as stale. */
    public const STALE_AFTER_DAYS = 30;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'evaluation_period_id',
        'goal_template_id',
        'title',
        'description',
        'measure',
        'start_value',
        'target_value',
        'current_value',
        'unit',
        'weight',
        'due_on',
        'status',
        'health',
        'last_check_in_at',
        'created_by',
        'closed_at',
    ];

    protected $attributes = ['status' => 'active', 'measure' => 'percent'];

    protected function casts(): array
    {
        return [
            'start_value' => 'decimal:2',
            'target_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'weight' => 'decimal:2',
            'due_on' => 'date',
            'last_check_in_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EvaluationPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(EvaluationPeriod::class, 'evaluation_period_id');
    }

    /**
     * The library entry it was started from, archived ones included.
     *
     * @return BelongsTo<GoalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(GoalTemplate::class, 'goal_template_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every check-in, newest first.
     *
     * @return HasMany<GoalCheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(GoalCheckIn::class)->latest('id');
    }

    /**
     * How far along it is, 0–100.
     */
    public function progress(): float
    {
        return GoalProgress::percent(
            (float) $this->start_value,
            (float) $this->target_value,
            (float) $this->current_value,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether an active goal has gone a month without anyone checking in.
     */
    public function isStale(): bool
    {
        $since = $this->last_check_in_at ?? $this->created_at;

        return $this->isActive()
            && $since !== null
            && $since->lt(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * @param  Builder<PerformanceGoal>  $query
     */
    public function scopeForPeriod(Builder $query, ?int $periodId): void
    {
        $query->when($periodId !== null, fn (Builder $q) => $q->where('evaluation_period_id', $periodId));
    }

    /**
     * @param  Builder<PerformanceGoal>  $query
     */
    public function scopeForEmployee(Builder $query, Employee|int $employee): void
    {
        $query->where('employee_id', $employee instanceof Employee ? $employee->id : $employee);
    }

    /**
     * Goals that still count toward attainment (a dropped goal does not).
     *
     * @param  Builder<PerformanceGoal>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', '!=', 'dropped');
    }

    /**
     * Search by the goal's title or its owner.
     *
     * @param  Builder<PerformanceGoal>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(fn (Builder $q) => $q
            ->where('title', $like, '%'.$term.'%')
            ->orWhereHas('employee', fn (Builder $e) => $e->search($term)));
    }
}
