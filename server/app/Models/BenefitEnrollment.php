<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's enrollment in a {@see BenefitPlan}: their status, an optional
 * member / policy reference, and the coverage dates. One row per employee per plan.
 */
class BenefitEnrollment extends Model
{
    use BelongsToOrganization;

    /** The lifecycle of an enrollment. */
    public const STATUSES = ['active', 'pending', 'waived', 'terminated'];

    protected $fillable = [
        'organization_id',
        'benefit_plan_id',
        'employee_id',
        'status',
        'reference_no',
        'enrolled_on',
        'ended_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<BenefitPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BenefitPlan::class, 'benefit_plan_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Limit to enrollments that count as covered (active).
     *
     * @param  Builder<BenefitEnrollment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
