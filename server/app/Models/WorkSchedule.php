<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A work pattern (shift) employees are assigned to: its hours, working days,
 * lateness grace and required hours. Configured under Company Setup → Work
 * Schedule & Holidays and read by Attendance. Archived rather than hard-deleted
 * so assigned employees keep a valid schedule.
 */
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'start_time',
        'end_time',
        'work_days',
        'grace_minutes',
        'required_hours',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'grace_minutes' => 'integer',
            'required_hours' => 'decimal:2',
        ];
    }

    /**
     * Employees assigned to this schedule.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
