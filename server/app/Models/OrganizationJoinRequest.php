<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\WorkspaceJoin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody at the door: a registered identity typed this organisation's join code
 * but could not be matched to an unclaimed roster line automatically, so they wait
 * here for HR to admit them (ADR 0026).
 *
 * There is at most one row per identity per organisation. Asking again after a
 * decline revives this row rather than queueing a second one, so the review screen
 * shows people, not attempts.
 *
 * Created and resolved through {@see WorkspaceJoin}.
 */
class OrganizationJoinRequest extends Model
{
    use BelongsToOrganization;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $fillable = [
        'organization_id',
        'user_id',
        'employee_id',
        'status',
        'decline_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The identity asking to join.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The roster line HR bound them to on approval, if any.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The HR user who approved or declined it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  Builder<OrganizationJoinRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }
}
