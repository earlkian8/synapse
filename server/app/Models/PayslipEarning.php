<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One earning line on a {@see Payslip} (basic pay, overtime, an allowance …),
 * optionally typed by an {@see AllowanceType}.
 */
class PayslipEarning extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'payslip_id',
        'allowance_type_id',
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
     * @return BelongsTo<AllowanceType, $this>
     */
    public function allowanceType(): BelongsTo
    {
        return $this->belongsTo(AllowanceType::class);
    }
}
