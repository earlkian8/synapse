<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\CalibrationWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A calibration session (ADR 0073): a meeting over one review cycle — or some of
 * its departments — in which submitted ratings are compared and moved, each move
 * with a reason ({@see CalibrationAdjustment}).
 *
 * What is in it is decided live by its scope, not a list: the cycle's
 * appraisals whose employee works in one of `department_ids` (null = all of
 * them). While it is open, appraisals submitted inside that scope are held back
 * from the employee; completing or cancelling it shares them
 * ({@see CalibrationWorkflow}).
 */
class CalibrationSession extends Model
{
    use BelongsToOrganization, HasHashid;

    public const STATUSES = ['open', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'evaluation_period_id',
        'name',
        'scheduled_for',
        'department_ids',
        'status',
        'notes',
        'facilitator_id',
        'completed_at',
    ];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'department_ids' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EvaluationPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(EvaluationPeriod::class, 'evaluation_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    /**
     * Who takes part in it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calibration_participants');
    }

    /**
     * Every band move made in it, oldest first.
     *
     * @return HasMany<CalibrationAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CalibrationAdjustment::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * The departments it covers, or null for the whole cycle.
     *
     * @return list<int>|null
     */
    public function departmentScope(): ?array
    {
        $ids = $this->department_ids;

        return is_array($ids) && $ids !== [] ? array_values(array_map('intval', $ids)) : null;
    }

    /**
     * Whether an employee in this department falls inside it.
     */
    public function covers(?int $departmentId): bool
    {
        $scope = $this->departmentScope();

        return $scope === null || ($departmentId !== null && in_array($departmentId, $scope, true));
    }

    /**
     * The cycle's appraisals inside it.
     *
     * @return Builder<PerformanceEvaluation>
     */
    public function evaluations(): Builder
    {
        return PerformanceEvaluation::query()
            ->forPeriod($this->evaluation_period_id)
            ->inDepartments($this->departmentScope());
    }

    /**
     * @param  Builder<CalibrationSession>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }
}
