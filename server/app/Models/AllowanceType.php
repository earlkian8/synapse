<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup lookup (ERD §2): a kind of additional earning (allowance) a
 * payslip line can be typed by — e.g. Rice Subsidy, Transportation. `is_taxable`
 * drives whether it forms part of taxable income.
 */
class AllowanceType extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'is_taxable',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
        ];
    }

    /**
     * The payslip earning lines typed by this allowance.
     *
     * @return HasMany<PayslipEarning, $this>
     */
    public function payslipEarnings(): HasMany
    {
        return $this->hasMany(PayslipEarning::class);
    }
}
