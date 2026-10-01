<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\LeaveCalculator;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's filed time off, with an approval lifecycle
 * (pending → approved / rejected, or cancelled). The number of working `days` is
 * computed server-side from the date range (see {@see LeaveCalculator}).
 * See ADR 0009.
 */
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    /**
     * The lifecycle states a request moves through.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days',
        'is_half_day',
        'half_day_period',
        'reason',
        'status',
        'filed_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'decimal:1',
            'is_half_day' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * The user who filed the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /**
     * The user who approved or rejected the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Whether the leave is in effect on the given date (approved and within range).
     */
    public function coversDate(CarbonInterface $date): bool
    {
        return $this->status === 'approved'
            && $this->start_date->lte($date)
            && $this->end_date->gte($date);
    }
}
