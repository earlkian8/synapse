<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether one enrollee came to one {@see TrainingSession} (ADR 0074). Present
 * and late both count as attended; excused leaves the session out of the
 * person's attendance rate; absent counts against it.
 */
class TrainingAttendance extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    /** The statuses that count as having attended. */
    public const ATTENDED = ['present', 'late'];

    protected $fillable = [
        'organization_id',
        'training_session_id',
        'training_enrollment_id',
        'status',
        'note',
        'marked_by',
    ];

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /**
     * @return BelongsTo<TrainingEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }
}
