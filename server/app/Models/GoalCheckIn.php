<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One update on a {@see PerformanceGoal} (ADR 0073): where it stood, how it is
 * going, and a note — by its owner or by HR or a manager. Append-only: the
 * goal's current value and health are the latest check-in's.
 */
class GoalCheckIn extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'performance_goal_id',
        'author_id',
        'value',
        'health',
        'note',
    ];

    protected function casts(): array
    {
        return ['value' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<PerformanceGoal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(PerformanceGoal::class, 'performance_goal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
