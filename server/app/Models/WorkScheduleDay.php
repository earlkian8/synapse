<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a {@see WorkSchedule}'s cycle (ADR 0037).
 *
 * A weekly schedule has seven of these, indexed 1 = Monday; a rotation has as
 * many as its `cycle_length_days`. The day carries its own segments — two or
 * more when the shift is split — its required minutes, and, for a flexible
 * schedule, the core window somebody must be present for.
 */
class WorkScheduleDay extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'work_schedule_id',
        'day_index',
        'is_rest_day',
        'segments',
        'required_minutes',
        'core_start',
        'core_end',
        'earliest_start',
        'latest_end',
        'unpaid_break_minutes',
    ];

    protected function casts(): array
    {
        return [
            'day_index' => 'integer',
            'is_rest_day' => 'boolean',
            'segments' => 'array',
            'required_minutes' => 'integer',
            'unpaid_break_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class)->withTrashed();
    }
}
