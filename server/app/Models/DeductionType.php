<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup lookup (ERD §2): a kind of deduction a payslip line can be
 * typed by. `kind` separates the statutory contributions (SSS / PhilHealth /
 * Pag-IBIG / withholding tax) from loans and other items; `computation` holds the
 * rate / bracket config the payroll calculator interprets.
 */
class DeductionType extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** The recognised deduction kinds. */
    public const KINDS = ['sss', 'philhealth', 'pagibig', 'withholding_tax', 'loan', 'other'];

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'is_mandatory',
        'computation',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'computation' => 'array',
        ];
    }
}
