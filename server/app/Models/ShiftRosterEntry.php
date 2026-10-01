<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "On this date, this person works this instead" — the one-off that beats every
 * schedule and assignment (ADR 0037): a swap, a Saturday call-in, a day off.
 *
 * It either borrows another template's pattern for the date (`work_schedule_id`)
 * or states its own `segments`; `is_rest_day` turns a working day off. One entry
 * per employee per date.
 */
class ShiftRosterEntry extends Model
{
    use BelongsToOrganization, HasHashid;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'date',
        'work_schedule_id',
        'segments',
        'required_minutes',
        'is_rest_day',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'segments' => 'array',
            'required_minutes' => 'integer',
            'is_rest_day' => 'boolean',
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
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
