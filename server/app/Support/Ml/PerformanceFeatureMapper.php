<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\EvaluationPeriod;

/**
 * Translates an {@see Employee} into the performance-forecast model's one input
 * (ADR 0045): `rating_latest`, the attainment (0–100) of the latest completed
 * appraisal whose period **ended before the period being forecast began**.
 *
 * That cut is the whole point. A forecast of a period may not read that period's
 * own appraisal — not a draft of it, and not a finished one — or it is reporting,
 * not forecasting. (The old mapper did both: it read the latest evaluation of any
 * status, including the target period's own draft, and fed the same rating in as
 * three separate inputs.)
 *
 * Nothing else is sent: nothing else the ERP records adds anything once the latest
 * rating is known (measured in `model/notebooks/02_performance_model.ipynb`).
 */
class PerformanceFeatureMapper
{
    /**
     * @return array<string, float>
     */
    public function features(AppraisalHistory $history): array
    {
        $latest = $history->latest();

        return $latest === null ? [] : ['rating_latest' => $latest['percent']];
    }

    /**
     * The part of an employee's record a forecast of `$target` may read.
     */
    public function window(Employee $employee, ?EvaluationPeriod $target): AppraisalHistory
    {
        return AppraisalHistory::of($employee)->before($target);
    }
}
