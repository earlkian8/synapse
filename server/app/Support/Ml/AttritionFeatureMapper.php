<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Support\CheckIns\CheckInResults;
use Carbon\CarbonImmutable;

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
 * needs to know where the survey drew its lines. It also builds the behavioural
 * indicators itself (ADR 0101): absences and late arrivals are read together as
 * withdrawal, so the ERP sends the two counts, never an indicator.
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

    /** Recognition older than this is read as this: the gap says "a long time" either way. */
    private const RECOGNITION_CAP_MONTHS = 60;

    /**
     * Build the feature dict for one employee. Only keys we can ground in real data
     * are returned; the inference service imputes any others.
     *
     * `$teamClimate` is each department's latest shown check-in result
     * ({@see CheckInResults::teamClimate()}), keyed by department id.
     *
     * @param  array<int, array{mood: float, engagement: float, leave_share: float}>  $teamClimate
     * @return array<string, float|int|string>
     */
    public function features(Employee $employee, array $teamClimate = []): array
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

        return $features + $this->signals($employee, $tenure, $teamClimate);
    }

    /**
     * What an organisation's own model can learn from but the general one can't, as
     * no survey asked it (ADR 0102) — each sent only when the record grounds it:
     *
     *  - **team climate** — the person's department's latest shown check-in result:
     *    mood (1–5), engagement (UWES-3, 0–6), and the share who often or always think
     *    about leaving. A department's figure, never the person's own answers.
     *  - **months since recognition** — since their latest award or kudos; for
     *    someone never recognised, their whole tenure (as a wait for a promotion is).
     *  - **leave days in the last year** — approved leave taken, for people here a
     *    year or more (a newer hire's year is not a year).
     *
     * @param  array<int, array{mood: float, engagement: float, leave_share: float}>  $teamClimate
     * @return array<string, float>
     */
    private function signals(Employee $employee, ?float $tenure, array $teamClimate): array
    {
        $signals = [];
        $attributes = $employee->getAttributes();

        if (($climate = $teamClimate[$employee->department_id] ?? null) !== null) {
            $signals['team_mood'] = (float) $climate['mood'];
            $signals['team_engagement'] = (float) $climate['engagement'];
            $signals['team_leave_thoughts'] = (float) $climate['leave_share'];
        }

        if (array_key_exists('last_award_on', $attributes) || array_key_exists('last_kudos_at', $attributes)) {
            $last = collect([$attributes['last_award_on'] ?? null, $attributes['last_kudos_at'] ?? null])
                ->filter()
                ->map(fn (string $at): CarbonImmutable => CarbonImmutable::parse($at))
                ->max();

            $months = $last !== null ? $last->diffInMonths(now()) : ($tenure !== null ? $tenure * 12 : null);

            if ($months !== null) {
                $signals['months_since_recognition'] = round(min(self::RECOGNITION_CAP_MONTHS, max(0.0, (float) $months)), 1);
            }
        }

        if (array_key_exists('leave_days_365d', $attributes) && $tenure !== null && $tenure >= 1) {
            $signals['leave_days_365d'] = round((float) ($attributes['leave_days_365d'] ?? 0), 1);
        }

        return $signals;
    }
}
