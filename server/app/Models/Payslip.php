<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's computed pay for a {@see PayrollPeriod}: the basic / overtime /
 * gross figures, the itemised earning and deduction lines, and the net. Every
 * money figure is derived server-side from the lines (see App\Support\Payroll),
 * never trusted from the client.
 */
class Payslip extends Model
{
    use BelongsToOrganization, HasHashid;

    /** draft (part of an unfinalized run) → released (shared with the employee). */
    public const STATUSES = ['draft', 'released'];

    protected $fillable = [
        'organization_id',
        'payroll_period_id',
        'employee_id',
        'basic_pay',
        'overtime_pay',
        'gross_pay',
        'total_earnings',
        'total_deductions',
        'net_pay',
        'days_worked',
        'status',
        'is_adjusted',
    ];

    protected function casts(): array
    {
        return [
            'basic_pay' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'days_worked' => 'decimal:2',
            'is_adjusted' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<PayslipEarning, $this>
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(PayslipEarning::class);
    }

    /**
     * @return HasMany<PayslipDeduction, $this>
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(PayslipDeduction::class);
    }
}
