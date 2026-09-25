<?php

namespace App\Support\Ml;

use App\Models\Employee;

/**
 * Translates an {@see Employee} into the promotion model's two inputs (ADR 0045),
 * each a fact the ERP records, in the unit the model was trained in:
 *
 *  - `rating_latest` — attainment (0–100) of the latest completed appraisal;
 *  - `rating_change` — that attainment minus the previous completed appraisal's,
 *    sent only when there is a previous one. Change is the strongest signal in the
 *    training data, so it is never invented: an employee with one appraisal is
 *    scored by a submodel that does not need it.
 *
 * Nothing else is sent. Department, salary and employment type were inputs of the
 * old model and are gone: the reference dataset's departments are not the
 * tenant's, its salaries are another currency and period, and employment type had
 * no effect. Two recorded facts were measured and left out on purpose: time since
 * promotion (in the reference the recently promoted are promoted again more often —
 * an artefact, not a practice) and overtime (it depends on role and policy, and a
 * score that rises with hours worked penalises part-time staff and carers).
 * Demographic attributes were never sent and still are not.
 *
 * Reads the employee's loaded `performanceEvaluations.period`, which
 * {@see PromotionReadinessAssessor} provides.
 */
class PromotionFeatureMapper
{
    /**
     * @return array<string, float>
     */
    public function features(Employee $employee, ?AppraisalHistory $history = null): array
    {
        $history ??= AppraisalHistory::of($employee);
        $latest = $history->latest();

        if ($latest === null) {
            return [];
        }

        $features = ['rating_latest' => $latest['percent']];

        if ($previous = $history->previous()) {
            $features['rating_change'] = round($latest['percent'] - $previous['percent'], 2);
        }

        return $features;
    }
}
