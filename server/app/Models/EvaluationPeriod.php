<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\EvaluationPeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup evaluation period (ERD §2): a review cycle (e.g. "H1 2026
 * Review") with a start / end date and a status lifecycle. Performance
 * evaluations are conducted within an `open` period.
 */
class EvaluationPeriod extends Model
{
    /** @use HasFactory<EvaluationPeriodFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    /** The lifecycle of a review cycle. */
    public const STATUSES = ['draft', 'open', 'closed'];

    protected $fillable = [
        'organization_id',
        'name',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return HasMany<PerformanceEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(PerformanceEvaluation::class);
    }

    /**
     * Limit to periods that accept new evaluations.
     *
     * @param  Builder<EvaluationPeriod>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    /**
     * Most recent cycle first.
     *
     * @param  Builder<EvaluationPeriod>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('start_date');
    }
}
