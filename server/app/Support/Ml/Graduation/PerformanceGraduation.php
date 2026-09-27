<?php

namespace App\Support\Ml\Graduation;

use App\Models\Employee;
use App\Support\Ml\AppraisalHistory;
use Carbon\CarbonImmutable;

/**
 * Performance Forecast's terms of graduation (ADR 0046). Its own model would learn
 * how ratings move *here*, from the example the general model reads: a person's
 * completed appraisal, and the one they received next.
 *
 * **One example per pair of consecutive completed appraisals** for the same person,
 * in different review cycles no more than about 13 months apart — a longer gap is a
 * lapse in appraising, not a cycle-to-cycle step. Every rating after the first is
 * known the day it is completed, so nothing waits for a follow-up period.
 */
class PerformanceGraduation implements GraduationSurface
{
    /**
     * Mirrors the inference service's minimums (model/synapse_ml/local/training.py):
     * 234 + one per input to pin the forecast's spread within 10 % (Riley et al.,
     * 2019), forecasting at least two different review cycles.
     */
    public const MINIMUM_COMPARISONS = 235;

    public const MINIMUM_CYCLES = 2;

    /** Share (%) of the pairs that must be on an unchanged form. */
    public const MINIMUM_SAME_FORM = 80;

    /** The longest gap, in days, between two appraisals still read as consecutive cycles. */
    public const MAX_GAP_DAYS = 400;

    public function key(): string
    {
        return 'performance';
    }

    public function trainingSet(): TrainingSet
    {
        $twoYearsAgo = CarbonImmutable::today()->subYears(2);
        $employees = Employee::query()
            ->with(['performanceEvaluations' => fn ($query) => $query->with('period:id,name,start_date,end_date')])
            ->get(['id']);

        $rows = [];
        $cycles = [];
        $counts = ['appraisals' => 0, 'comparisons' => 0, 'same_form' => 0, 'lapsed' => 0, 'recent_comparisons' => 0];

        foreach ($employees as $employee) {
            $appraisals = array_values(array_filter(AppraisalHistory::of($employee)->completed, fn (array $a): bool => $a['ends'] !== null));
            $counts['appraisals'] += count($appraisals);

            for ($i = 1; $i < count($appraisals); $i++) {
                [$earlier, $later] = [$appraisals[$i - 1], $appraisals[$i]];
                $gap = CarbonImmutable::instance($earlier['ends'])->diffInDays(CarbonImmutable::instance($later['ends']));

                if ($gap <= 0 || $gap > self::MAX_GAP_DAYS
                    || ($earlier['period'] !== null && $earlier['period'] === $later['period'])) {
                    $counts['lapsed']++;

                    continue;
                }

                $cycle = $later['period'] !== null ? "period-{$later['period']}" : $later['ends']->format('Y-m');
                $cycles[$cycle] = true;
                $counts['comparisons']++;
                $counts['same_form'] += (int) ($earlier['form'] === $later['form']);
                $counts['recent_comparisons'] += (int) CarbonImmutable::instance($later['ends'])->gt($twoYearsAgo);

                $rows[] = [
                    'group' => (string) $employee->id,
                    'features' => ['rating_latest' => $earlier['percent']],
                    'outcome' => $later['percent'],
                    'cycle' => $cycle,
                ];
            }
        }

        $counts['cycles'] = count($cycles);

        return new TrainingSet($rows, $counts);
    }

    public function started(TrainingSet $set): bool
    {
        return $set->count('appraisals') > 0;
    }

    public function requirements(TrainingSet $set, FieldCounts $counts): array
    {
        $comparisons = (int) $set->count('comparisons');
        $sameForm = $comparisons > 0 ? round(100 * $set->count('same_form') / $comparisons, 1) : 0.0;
        $lapsed = (int) $set->count('lapsed');

        return [
            new Requirement(
                key: 'comparisons',
                label: 'Ratings compared with the one before',
                group: 'volume',
                current: $comparisons,
                required: self::MINIMUM_COMPARISONS,
                unit: 'comparisons',
                unitOne: 'comparison',
                summary: 'One comparison is a person’s rating in one cycle set beside their rating in the next — the example a forecast learns from.',
                action: 'Complete an appraisal for everyone in every review cycle. Each person appraised in two cycles in a row adds one.',
                basis: 'A forecast’s likely range is built from how far off it has been. Pinning that spread down to within 10% takes about 234 examples plus one per input (Riley et al., 2019) — 235 here.',
                source: 'Pairs of completed appraisals for the same person in consecutive review cycles, no more than about 13 months apart.',
                outlook: Pace::outlook(self::MINIMUM_COMPARISONS - $comparisons, (int) $set->count('recent_comparisons'), 'comparisons', 'comparison'),
                note: $lapsed > 0
                    ? ($lapsed === 1
                        ? '1 pair of appraisals can’t count: they were over 13 months apart or in the same cycle.'
                        : "{$lapsed} pairs of appraisals can’t count: they were over 13 months apart or in the same cycle.")
                    : null,
            ),
            new Requirement(
                key: 'cycles',
                label: 'Review cycles with the one before to compare',
                group: 'volume',
                current: $set->count('cycles'),
                required: self::MINIMUM_CYCLES,
                unit: 'review cycles',
                unitOne: 'review cycle',
                summary: 'A cycle counts when the cycle before it also had appraisals — so it takes three cycles in a row to count two.',
                action: 'Run review cycles back to back, and complete the appraisals in each.',
                basis: 'Learning from a single pair of cycles would learn that one year’s mood — a strict year, or a generous one. Two different steps are the least that shows what carries over.',
                source: 'Distinct review cycles whose ratings have an earlier rating to compare with.',
            ),
            new Requirement(
                key: 'same_form',
                label: 'Comparisons scored on an unchanged form',
                group: 'quality',
                format: 'percent',
                current: $sameForm,
                required: self::MINIMUM_SAME_FORM,
                summary: 'A rating only compares with the next when both were scored on the same form.',
                action: 'Avoid editing an appraisal framework’s sections or rating bands between cycles. If you must, this share dips for a cycle.',
                basis: 'Appraisal frameworks can be edited. A forecast learned across an edit tracks the form, not the person. When at least four in five comparisons are like for like, that noise can’t drive what it learns.',
                source: 'The share of the comparisons above where both appraisals used the same sections and rating bands.',
                derived: true,
                note: $comparisons === 0 ? 'There are no comparisons yet.' : null,
            ),
        ];
    }

    public function fields(FieldCounts $counts): array
    {
        $total = $counts->active();

        return [
            ['key' => 'rating_latest', 'label' => 'Latest completed appraisal', 'source' => 'Performance', 'state' => 'supplied',
                'covered' => $counts->appraised(), 'total' => $total,
                'note' => 'The one input a forecast needs: the latest appraisal completed before the period being forecast. Employees without one are listed as not forecast.'],
            ['key' => 'rating_previous', 'label' => 'Previous completed appraisal', 'source' => 'Performance', 'state' => 'available',
                'covered' => $counts->appraised(2), 'total' => $total,
                'note' => 'Shown on each person’s trajectory, but once the latest appraisal is known it adds nothing to the forecast.'],
            ['key' => 'date_hired', 'label' => 'Hire date', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->hired(), 'total' => $total,
                'note' => 'Recorded, but tenure adds nothing to the forecast once the latest appraisal is known.'],
            ['key' => 'employment_type', 'label' => 'Employment type', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->typed(), 'total' => $total,
                'note' => 'Recorded; no bearing on the next appraisal.'],
            ['key' => 'department', 'label' => 'Department', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->inDepartment(), 'total' => $total,
                'note' => 'Recorded; no bearing on the next appraisal.'],
            ['key' => 'certifications', 'label' => 'Certifications', 'source' => 'Employee profile', 'state' => 'available',
                'covered' => $counts->certified(), 'total' => $total,
                'note' => 'Recorded, but they add nothing to the forecast.'],
            ['key' => 'attendance', 'label' => 'Attendance, last 90 days', 'source' => 'Attendance', 'state' => 'available',
                'covered' => $counts->attendanceTracked(), 'total' => $total,
                'note' => 'Recorded daily, but it has no bearing on the next appraisal, so it isn’t used.'],
            ['key' => 'training', 'label' => 'Trainings completed, last 12 months', 'source' => 'Training', 'state' => 'available',
                'covered' => $counts->trained(), 'total' => $total,
                'note' => 'Tracked, but it showed no bearing on the next appraisal.'],
            ['key' => 'deadline_adherence', 'label' => 'Deadline adherence', 'source' => 'Not collected', 'state' => 'missing',
                'covered' => 0, 'total' => $total,
                'note' => 'No part of the system tracks projects or tasks, so delivery reliability can’t be measured.'],
            ['key' => 'peer_feedback', 'label' => 'Peer feedback', 'source' => 'Not collected', 'state' => 'missing',
                'covered' => 0, 'total' => $total,
                'note' => 'No part of the system collects peer or 360-degree feedback.'],
        ];
    }
}
