<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Payroll\BenefitContributionGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One statutory benefit contribution (ERD §7): an employee's SSS / PhilHealth /
 * Pag-IBIG **employee** + **employer** share for a pay period, derived from the
 * run's statutory payslip deductions (see {@see BenefitContributionGenerator}).
 * The basis for the monthly government remittance report.
 */
class BenefitContribution extends Model
{
    use BelongsToOrganization;

    /** The government benefits tracked here. */
    public const BENEFITS = ['sss', 'philhealth', 'pagibig'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'payroll_period_id',
        'period',
        'benefit',
        'employee_share',
        'employer_share',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'employee_share' => 'decimal:2',
            'employer_share' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * Scope to a remittance month ("YYYY-MM").
     *
     * @param  Builder<BenefitContribution>  $query
     */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }
}
