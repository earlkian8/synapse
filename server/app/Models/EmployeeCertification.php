<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licence, board exam or training an employee holds, with when it was issued
 * and when it expires. One earned by completing a training program (ADR 0075)
 * is tied to that enrollment and numbered; it is issued and withdrawn by
 * Training, never edited on the profile.
 */
class EmployeeCertification extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'training_enrollment_id',
        'name',
        'issuer',
        'certificate_no',
        'issued_date',
        'expiry_date',
        'expiry_notified_at',
        'file',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'expiry_date' => 'date',
            'expiry_notified_at' => 'datetime',
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
     * The enrollment whose completion earned it, when it came from training.
     *
     * @return BelongsTo<TrainingEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    public function fromTraining(): bool
    {
        return $this->training_enrollment_id !== null;
    }

    /**
     * Certificates earned through training.
     *
     * @param  Builder<EmployeeCertification>  $query
     */
    public function scopeFromTraining(Builder $query): void
    {
        $query->whereNotNull('training_enrollment_id');
    }
}
