<?php

namespace App\Support\Ml;

use App\Models\Employee;

/**
 * Translates an {@see Employee} into the feature vector the attrition model
 * expects. The model was trained on the attrition surveys
 * (model/synapse_ml/attrition/), whose eight questions were chosen so that every
 * one has an answer in the ERP:
 *
 *  - employment type, tenure, monthly salary — the employee record;
 *  - time since the last promotion, and whether there ever was one — promotions;
 *  - overtime hours, absences and late arrivals over the last 90 days — attendance.
 *
 * Values are sent in their natural units (years, pesos, hours, days, times). The
 * model's own pipeline bands them exactly as the survey did, so the ERP never
 * needs to know where the survey drew its lines.
 *
 * A value is only sent when it is grounded in a record. In particular, an employee
 * with no attendance tracked over the window gets **no** attendance features — sending
 * zeros would claim a perfect record nobody observed — and the pipeline imputes them
 * instead, which the assessor reflects in a lower confidence.
 *
 * The expected loaded relations and aggregates are provided by
 * {@see AttritionRiskAssessor}: `promotions` (latest first), and the attendance
 * aggregates named below.
 */
class AttritionFeatureMapper
{
    /** The attendance window the survey asked about ("your last 3 months"). */
    public const WINDOW_DAYS = 90;

    /** ERP employment types the model knows (the survey's were mapped onto these). */
    private const EMPLOYMENT_TYPES = ['regular', 'probationary', 'part_time', 'contractual'];

    /**
     * Build the feature dict for one employee. Only keys we can ground in real data
     * are returned; the inference service imputes any others.
     *
     * @return array<string, float|int|string>
     */
    public function features(Employee $employee): array
    {
        $features = [];
        $now = now();

        // ── Employment ─────────────────────────────────────────────────────
        if (in_array($employee->employment_type, self::EMPLOYMENT_TYPES, true)) {
            $features['employment_type'] = $employee->employment_type;
        }

        $tenure = $employee->date_hired
            ? max(0.0, round($employee->date_hired->diffInYears($now), 2))
            : null;

        if ($tenure !== null) {
            $features['tenure_years'] = $tenure;
        }

        if ($employee->basic_salary !== null && (float) $employee->basic_salary > 0) {
            $features['monthly_salary'] = round((float) $employee->basic_salary, 2);
        }

        // ── Promotion cadence ──────────────────────────────────────────────
        $lastPromotion = $employee->relationLoaded('promotions') ? $employee->promotions->first() : null;

        if ($lastPromotion?->effective_date) {
            $features['ever_promoted'] = 1;
            $features['years_since_promotion'] = max(0.0, round($lastPromotion->effective_date->diffInYears($now), 2));
        } elseif ($employee->relationLoaded('promotions') && $tenure !== null) {
            // Never promoted: the wait is the whole tenure — the same substitution
            // the training data makes for "I was never promoted".
            $features['ever_promoted'] = 0;
            $features['years_since_promotion'] = $tenure;
        }

        // ── Attendance, last 90 days ───────────────────────────────────────
        if ((int) ($employee->attendance_days_90d ?? 0) > 0) {
            $features['absences_90d'] = (int) $employee->absences_90d;
            $features['lates_90d'] = (int) $employee->lates_90d;
            $features['overtime_hours_90d'] = round((int) ($employee->overtime_minutes_90d ?? 0) / 60, 2);
        }

        return $features;
    }
}
