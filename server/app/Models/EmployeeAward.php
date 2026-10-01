<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recognition given to an employee (ERD §9): the {@see AwardType}, the date it
 * was awarded, the reason, and the user who granted it.
 */
class EmployeeAward extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'award_type_id',
        'awarded_on',
        'reason',
        'awarded_by',
    ];

    protected function casts(): array
    {
        return [
            'awarded_on' => 'date',
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
     * The award's type. Includes archived (soft-deleted) types so a past award
     * still shows what it was for even after the type is retired.
     *
     * @return BelongsTo<AwardType, $this>
     */
    public function awardType(): BelongsTo
    {
        return $this->belongsTo(AwardType::class)->withTrashed();
    }

    /**
     * The user who granted the recognition.
     *
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    /**
     * Most recently awarded first.
     *
     * @param  Builder<EmployeeAward>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('awarded_on')->orderByDesc('id');
    }
}
