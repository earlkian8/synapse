<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\PerformanceEvaluation;

/**
 * Translates an {@see Employee} into the feature vector the attrition model
 * expects. The model was deliberately trained on **only the columns the ERP can
 * supply** (see model/build_notebooks.py · the ERP-servable feature set), so this
 * maps the signals the HR system actually holds — compensation, overtime
 * behaviour, tenure, promotion cadence, latest performance rating, recent
 * training — and omits everything else (the model's pipeline imputes the rest,
 * and demographic / protected attributes are never sent).
 *
 * The expected loaded relations / aggregates are provided by
 * {@see AttritionRiskAssessor}:
 *   - `department` (id, name)
 *   - `performanceEvaluations` (scored, latest first)
 *   - `promotions` (latest first)
 *   - `recent_overtime_minutes` — withSum of overtime over the recent window
 *   - `training_last_year_count` — withCount of enrollments in the last year
 */
class AttritionFeatureMapper
{
    /**
     * The model inputs we try to ground in real HR data. Confidence (see
     * {@see AttritionRiskAssessor}) is the share of these present for an employee;
     * the rest are imputed by the pipeline.
     */
    public const KEY_FEATURES = [
        'MonthlyIncome',
        'OverTime',
        'YearsAtCompany',
        'YearsInCurrentRole',
        'YearsSinceLastPromotion',
        'PerformanceRating',
        'TrainingTimesLastYear',
    ];

    /** Window (days) over which recent overtime is summed to decide the OverTime flag. */
    private const OVERTIME_WINDOW_DAYS = 90;

    /** Total overtime (minutes) in the window at/above which OverTime is "Yes". */
    private const OVERTIME_THRESHOLD_MINUTES = 120;

    /**
     * Build the feature dict for one employee. Only keys we can ground in real
     * data are returned; the inference service fills any others by imputation.
     *
     * @return array<string, float|int|string>
     */
    public function features(Employee $employee): array
    {
        $features = [];

        // ── Compensation ───────────────────────────────────────────────────
        if ($employee->basic_salary !== null) {
            $features['MonthlyIncome'] = (float) $employee->basic_salary;
        }

        // ── Tenure ─────────────────────────────────────────────────────────
        $now = now();
        $yearsAtCompany = $employee->date_hired
            ? max(0.0, round($employee->date_hired->floatDiffInYears($now), 2))
            : null;

        if ($yearsAtCompany !== null) {
            $features['YearsAtCompany'] = $yearsAtCompany;
        }

        // Latest promotion anchors "current role" and "since last promotion".
        $lastPromotion = $employee->relationLoaded('promotions') ? $employee->promotions->first() : null;
        $lastPromotedAt = $lastPromotion?->effective_date;

        if ($lastPromotedAt) {
            $features['YearsSinceLastPromotion'] = max(0.0, round($lastPromotedAt->floatDiffInYears($now), 2));
            $features['YearsInCurrentRole'] = $features['YearsSinceLastPromotion'];
        } elseif ($yearsAtCompany !== null) {
            // Never promoted: time in role = tenure.
            $features['YearsSinceLastPromotion'] = $yearsAtCompany;
            $features['YearsInCurrentRole'] = $yearsAtCompany;
        }

        // ── Overtime behaviour (from attendance) ───────────────────────────
        // A regular-overtime flag, the model's strongest single driver. We sum
        // approved/computed overtime over the recent window and threshold it.
        $recentOvertime = $employee->getAttribute('recent_overtime_minutes');

        if ($recentOvertime !== null) {
            $features['OverTime'] = (int) $recentOvertime >= self::OVERTIME_THRESHOLD_MINUTES ? 'Yes' : 'No';
        }

        // ── Latest performance rating (1–5 → the model's 1–4 scale) ────────
        $latestRating = $this->latestRating($employee);

        if ($latestRating !== null) {
            // The training data's PerformanceRating tops out at 4; clamp so an
            // overall_score of 5 doesn't fall outside the learnt distribution.
            $features['PerformanceRating'] = max(1, min(4, (int) round($latestRating)));
        }

        // ── Recent training participation ──────────────────────────────────
        $trainingCount = $employee->getAttribute('training_last_year_count');

        if ($trainingCount !== null) {
            $features['TrainingTimesLastYear'] = (int) $trainingCount;
        }

        // ── Department (matched when the tenant names align; else neutralised
        //    by the model's handle_unknown="ignore" one-hot encoder) ─────────
        if ($employee->relationLoaded('department') && $employee->department) {
            $features['Department'] = $employee->department->name;
        }

        return $features;
    }

    /**
     * This employee's most recent scored overall rating (1–5), or null.
     */
    private function latestRating(Employee $employee): ?float
    {
        if (! $employee->relationLoaded('performanceEvaluations')) {
            return null;
        }

        $latest = $employee->performanceEvaluations
            ->first(fn (PerformanceEvaluation $e): bool => $e->overall_score !== null);

        return $latest?->overall_score !== null ? (float) $latest->overall_score : null;
    }
}
