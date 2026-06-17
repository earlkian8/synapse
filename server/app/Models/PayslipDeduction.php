<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deduction line on a {@see Payslip} (SSS, PhilHealth, Pag-IBIG, withholding
 * tax, a loan …), optionally typed by a {@see DeductionType}.
 */
class PayslipDeduction extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'payslip_id',
        'deduction_type_id',
        'label',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /**
     * @return BelongsTo<DeductionType, $this>
     */
    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }
}
