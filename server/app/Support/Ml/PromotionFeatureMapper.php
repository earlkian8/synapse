<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\PerformanceEvaluation;

/**
 * Translates an {@see Employee} into the feature vector the promotion model
 * expects. We map the signals the HR system actually holds — tenure, performance
 * history, certifications, salary, employment type — and deliberately omit
 * everything else (the model's pipeline imputes the rest, and demographic /
 * protected attributes are never sent).
 *
 * The expected loaded relations are provided by
 * {@see PromotionReadinessAssessor}: `certifications_count`, scored
 * `performanceEvaluations` (latest first), and `promotions` (latest first).
 */
class PromotionFeatureMapper
{
    /** ERP employment types → the model's vocabulary. */
    private const EMPLOYMENT_TYPE = [
        'regular' => 'Full-time',
        'probationary' => 'Full-time',
        'part_time' => 'Part-time',
        'contractual' => 'Contract',
    ];

    /**
     * Build the feature dict for one employee. Only keys we can ground in real
     * data are returned; the inference service fills any others by imputation.
     *
     * @return array<string, float|int|string>
     */
    public function features(Employee $employee): array
    {
        $features = [];

        // ── Tenure ──────────────────────────────────────────────────────────
        $now = now();
        $yearsAtCompany = $employee->date_hired
            ? max(0.0, round($employee->date_hired->floatDiffInYears($now), 2))
            : null;

        if ($yearsAtCompany !== null) {
            $features['years_at_company'] = $yearsAtCompany;
        }

        // Latest promotion anchors "current role" and "since last promotion".
        $lastPromotion = $employee->relationLoaded('promotions') ? $employee->promotions->first() : null;
        $lastPromotedAt = $lastPromotion?->effective_date;

        if ($lastPromotedAt) {
            $features['years_since_last_promotion'] = max(0.0, round($lastPromotedAt->floatDiffInYears($now), 2));
            $features['years_in_current_role'] = $features['years_since_last_promotion'];
        } elseif ($yearsAtCompany !== null) {
            // Never promoted: time in role = tenure.
            $features['years_since_last_promotion'] = $yearsAtCompany;
            $features['years_in_current_role'] = $yearsAtCompany;
        }

        // ── Performance history (1–5 evaluations → the model's scales) ──────
        $history = $this->scoredHistory($employee);

        if (isset($history[0])) {
            // performance_score is on a 0–100 scale; manager_rating stays 1–5.
            $features['performance_score'] = round($history[0] * 20, 1);
            $features['manager_rating'] = round($history[0], 2);
            $features['kpi_achievement_percent'] = round($history[0] / 5 * 100, 1);
        }
        if (isset($history[1])) {
            $features['performance_last_year'] = round($history[1] * 20, 1);
        }
        if (isset($history[2])) {
            $features['performance_two_years_ago'] = round($history[2] * 20, 1);
        }

        // ── Credentials & compensation ─────────────────────────────────────
        if ($employee->certifications_count !== null) {
            $features['certifications_count'] = (int) $employee->certifications_count;
        }
        if ($employee->basic_salary !== null) {
            $features['salary'] = (float) $employee->basic_salary;
        }

        // ── Categoricals the model knows ───────────────────────────────────
        if ($mapped = self::EMPLOYMENT_TYPE[$employee->employment_type] ?? null) {
            $features['employment_type'] = $mapped;
        }
        if ($employee->relationLoaded('department') && $employee->department) {
            $features['department'] = $employee->department->name;
        }

        return $features;
    }

    /**
     * This employee's scored overall ratings (1–5), most recent first.
     *
     * @return list<float>
     */
    private function scoredHistory(Employee $employee): array
    {
        if (! $employee->relationLoaded('performanceEvaluations')) {
            return [];
        }

        return $employee->performanceEvaluations
            ->filter(fn (PerformanceEvaluation $e): bool => $e->overall_score !== null)
            ->map(fn (PerformanceEvaluation $e): float => (float) $e->overall_score)
            ->values()
            ->all();
    }
}
