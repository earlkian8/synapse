<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a reward (ADR 0071): the points it took, and what became of it
 * — fulfilled or declined by HR, or cancelled by the person while it waited. A
 * decline or a cancel gives the points back.
 */
class RewardRedemption extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['pending', 'fulfilled', 'declined', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'reward_id',
        'employee_id',
        'cost',
        'status',
        'note',
        'response_note',
        'handled_by',
        'handled_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * The reward, archived ones included.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @param  Builder<RewardRedemption>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
