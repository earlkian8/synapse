<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\CheckIns\CheckInQuestions;
use App\Support\CheckIns\CheckInResults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's monthly check-in (ADR 0102): how they felt at work, how satisfied
 * they are, how engaged (UWES-3) and how often they have thought about leaving —
 * the questions are {@see CheckInQuestions}.
 *
 * **Confidential, not anonymous.** The row names its employee, so the person can see
 * and change their own answers and answers once a month. Nobody else reads it: the
 * screens, the API, the assistant and the data export never show one person's
 * answers; HR sees only {@see CheckInResults}, for groups of five or more. `note` is
 * for the person alone and is encrypted at rest.
 *
 * @property CarbonImmutable $period
 */
class CheckIn extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'department_id',
        'period',
        'mood',
        'satisfaction',
        'energy',
        'enthusiasm',
        'immersion',
        'leave_thoughts',
        'note',
        'submitted_at',
    ];

    /**
     * Never serialised by accident: a check-in is only ever shaped for its owner,
     * by {@see CheckInQuestions::present()}.
     *
     * @var list<string>
     */
    protected $hidden = ['note'];

    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'mood' => 'integer',
            'satisfaction' => 'integer',
            'energy' => 'integer',
            'enthusiasm' => 'integer',
            'immersion' => 'integer',
            'leave_thoughts' => 'integer',
            'note' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
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
     * The UWES-3 engagement score: the mean of its three items, 0–6.
     */
    public function engagement(): float
    {
        return round(($this->energy + $this->enthusiasm + $this->immersion) / 3, 2);
    }

    /**
     * @param  Builder<CheckIn>  $query
     */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->whereDate('period', $period);
    }
}
