<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payroll run — a pay period (start/end) with its pay date and a status
 * lifecycle. Its {@see Payslip} rows are computed from each employee's basic
 * salary and the attendance recorded in the window (see App\Support\Payroll).
 */
class PayrollPeriod extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /**
     * The run's lifecycle: drafted → processing (payslips generated) → finalized
     * (locked) → paid (disbursed).
     *
     * @var list<string>
     */
    public const STATUSES = ['draft', 'processing', 'finalized', 'paid'];

    protected $fillable = [
        'organization_id',
        'name',
        'start_date',
        'end_date',
        'pay_date',
        'status',
        'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'pay_date' => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Whether the run is locked from re-processing (finalized or paid).
     */
    public function isLocked(): bool
    {
        return in_array($this->status, ['finalized', 'paid'], true);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  Builder<PayrollPeriod>  $query
     */
    public function scopeStatus(Builder $query, ?string $status): void
    {
        if ($status !== null && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
    }
}
