<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use BelongsToOrganization, HasFactory;

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
