<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One blueprint sign-off on an {@see OffboardingProgram}: the item label and who
 * owns the sign-off — a fixed department, the departing employee's own department
 * (`use_employee_department`, resolved at instantiation), or nobody (unassigned).
 */
class OffboardingProgramItem extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'offboarding_program_id',
        'item',
        'department_id',
        'use_employee_department',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'use_employee_department' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<OffboardingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(OffboardingProgram::class, 'offboarding_program_id');
    }

    /**
     * The department responsible for this sign-off (when fixed).
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }
}
