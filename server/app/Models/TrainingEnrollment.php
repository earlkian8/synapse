<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's enrollment in a {@see TrainingProgram}: their status, an optional
 * completion score + timestamp, and remarks. One row per employee per program.
 */
class TrainingEnrollment extends Model
{
    use BelongsToOrganization;

    /** The lifecycle of an enrollment. */
    public const STATUSES = ['enrolled', 'completed', 'dropped'];

    protected $fillable = [
        'organization_id',
        'training_program_id',
        'employee_id',
        'status',
        'score',
        'completed_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TrainingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Limit to enrollments that occupy a seat (not dropped).
     *
     * @param  Builder<TrainingEnrollment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', 'dropped');
    }
}
