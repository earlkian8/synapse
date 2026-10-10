<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Recognition\NominationWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One colleague nominating another for an award (ADR 0071), with why. Pending
 * until HR approves it — giving the award, linked here — or turns it down, or
 * the nominator withdraws it ({@see NominationWorkflow}).
 */
class AwardNomination extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn'];

    protected $fillable = [
        'organization_id',
        'award_type_id',
        'employee_id',
        'nominated_by',
        'nominator_employee_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'employee_award_id',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * The nominee.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The award type, archived ones included.
     *
     * @return BelongsTo<AwardType, $this>
     */
    public function awardType(): BelongsTo
    {
        return $this->belongsTo(AwardType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function nominatorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'nominator_employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The award an approval gave.
     *
     * @return BelongsTo<EmployeeAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(EmployeeAward::class, 'employee_award_id');
    }

    /**
     * @param  Builder<AwardNomination>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
