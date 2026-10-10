<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rating moved in a calibration session (ADR 0073): from which band to which,
 * why, and by whom. Every move is its own row, so the history of a rating is
 * readable; the appraisal carries the latest.
 */
class CalibrationAdjustment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'calibration_session_id',
        'performance_evaluation_id',
        'from_band',
        'from_label',
        'to_band',
        'to_label',
        'reason',
        'adjusted_by',
    ];

    /**
     * @return BelongsTo<CalibrationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /**
     * @return BelongsTo<PerformanceEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(PerformanceEvaluation::class, 'performance_evaluation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
