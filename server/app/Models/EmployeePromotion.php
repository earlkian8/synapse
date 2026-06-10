<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePromotion extends Model
{
    protected $fillable = [
        'employee_id',
        'from_position_id',
        'to_position_id',
        'from_salary',
        'to_salary',
        'effective_date',
        'reason',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'from_salary' => 'decimal:2',
            'to_salary' => 'decimal:2',
            'effective_date' => 'date',
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
     * @return BelongsTo<Position, $this>
     */
    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }
}
