<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ClearanceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single clearance sign-off on an {@see OffboardingCase}: an item the
 * departing employee must settle (return a laptop, clear a cash advance, hand
 * over knowledge), owned by a responsible department and signed off — or
 * flagged — by a user.
 */
class ClearanceItem extends Model
{
    /** @use HasFactory<ClearanceItemFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * The states a clearance item may be in. `cleared` counts as resolved;
     * `flagged` surfaces an outstanding issue that blocks the exit.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'cleared', 'flagged'];

    protected $fillable = [
        'organization_id',
        'offboarding_case_id',
        'item',
        'department_id',
        'status',
        'remarks',
        'cleared_by',
        'cleared_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cleared_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<OffboardingCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    /**
     * The department responsible for this sign-off.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * The user who signed this item off (or flagged it).
     *
     * @return BelongsTo<User, $this>
     */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by')->withTrashed();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the item has been signed off.
     */
    public function isCleared(): bool
    {
        return $this->status === 'cleared';
    }
}
