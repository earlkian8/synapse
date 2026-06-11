<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OnboardingProgramTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blueprint task inside an {@see OnboardingProgram}. Carries a relative due
 * offset (days after the case start) rather than an absolute date, so the same
 * template produces sensible deadlines for any hire.
 */
class OnboardingProgramTask extends Model
{
    /** @use HasFactory<OnboardingProgramTaskFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'onboarding_program_id',
        'title',
        'description',
        'category',
        'due_offset_days',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'due_offset_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OnboardingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(OnboardingProgram::class, 'onboarding_program_id');
    }
}
