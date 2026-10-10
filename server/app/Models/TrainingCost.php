<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount spent on a {@see TrainingProgram} (ADR 0075): what it was for, how
 * much, and the day it was incurred — the day decides which year's budget it
 * is read against.
 */
class TrainingCost extends Model
{
    use BelongsToOrganization;

    public const CATEGORIES = ['trainer_fee', 'venue', 'materials', 'travel', 'meals', 'certification', 'other'];

    protected $fillable = [
        'organization_id',
        'training_program_id',
        'category',
        'description',
        'amount',
        'incurred_on',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
        ];
    }

    /**
     * The program, archived ones included: money spent stays spent.
     *
     * @return BelongsTo<TrainingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
