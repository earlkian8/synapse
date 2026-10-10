<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The organisation's training budget for one calendar year (ADR 0075). What was
 * spent is never stored here: it is the sum of the {@see TrainingCost}s incurred
 * that year.
 */
class TrainingBudget extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'year',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount' => 'decimal:2',
        ];
    }
}
