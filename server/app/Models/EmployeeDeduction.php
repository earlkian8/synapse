<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring per-employee deduction (e.g. a loan): a fixed peso amount of a
 * {@see DeductionType} taken from an individual employee every pay period, on top
 * of the mandatory statutory deductions. Active items become deduction lines on a
 * payslip when the run is processed (see App\Support\Payroll\PayrollProcessor).
 */
class EmployeeDeduction extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'deduction_type_id',
        'amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
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
     * @return BelongsTo<DeductionType, $this>
     */
    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }
}
