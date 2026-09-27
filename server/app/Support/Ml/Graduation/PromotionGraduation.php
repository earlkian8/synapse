<?php

namespace App\Support\Ml\Graduation;

use App\Models\Employee;
use App\Support\Ml\AppraisalHistory;
use Carbon\CarbonImmutable;

/**
 * Promotion Readiness's terms of graduation (ADR 0046). Its own model would learn
 * who gets promoted *here*, from examples of the shape the general model reads: a
 * completed appraisal (its attainment, and its change on the one before) and
 * whether a promotion followed.
 *
 * **One example per completed appraisal.** Its follow-up period runs until the
 * person's next completed appraisal, or a year, whichever comes first; a promotion
 * inside it is credited to that appraisal — the latest one anybody deciding the
 * promotion could have read — so no promotion counts twice. An example is used only
 * once its follow-up period has closed; before then "not promoted" is not yet a
 * fact. Someone who left before it closed without a promotion is left out: whether
 * they would have been promoted is unknown.
 */
class PromotionGraduation implements GraduationSurface
{
    /**
     * Mirrors the inference service's minimums (model/synapse_ml/local/training.py):
     * 100 of each outcome (Collins, Ogundimu & Altman, 2016), and half the
     * promotions with an earlier appraisal to show improvement.
     */
    public const MINIMUM_PROMOTED = 100;

    public const MINIMUM_NOT_PROMOTED = 100;

    public const MINIMUM_PROMOTED_WITH_CHANGE = 50;

    /** Share (%) of appraisal pairs that must be on an unchanged form. */
    public const MINIMUM_SAME_FORM = 80;

    public function key(): string
    {
        return 'promotion';
    }

    public function trainingSet(): TrainingSet
    {
        $today = CarbonImmutable::today();
        $employees = Employee::query()
            ->with([
                'performanceEvaluations' => fn ($query) => $query->with('period:id,name,start_date,end_date'),
                'promotions:id,employee_id,effective_date',
                'offboardingCase:id,employee_id,type,status,last_working_day,completed_at',
            ])
            ->get(['id', 'employment_status']);

        $rows = [];
        $counts = ['appraisals' => 0, 'promoted' => 0, 'not_promoted' => 0, 'promoted_with_change' => 0,
            'with_change' => 0, 'same_form' => 0, 'promotions' => 0, 'promotions_pending' => 0,
            'promotions_unusable' => 0, 'left_early' => 0, 'recent_promotions' => 0];

        foreach ($employees as $employee) {
            $appraisals = array_values(array_filter(AppraisalHistory::of($employee)->completed, fn (array $a): bool => $a['ends'] !== null));
            $promotions = $employee->promotions->pluck('effective_date')->filter()
                ->map(fn ($date): CarbonImmutable => CarbonImmutable::instance($date)->startOfDay())
                ->sort()->values();
            $departure = Departure::of($employee);
            $credited = [];

            $counts['appraisals'] += count($appraisals);
            $counts['promotions'] += $promotions->count();
            $counts['recent_promotions'] += $promotions->filter(fn (CarbonImmutable $d): bool => $d->gt($today->subYears(2)))->count();

            foreach ($appraisals as $i => $appraisal) {
                $from = CarbonImmutable::instance($appraisal['ends'])->startOfDay();
                $next = isset($appraisals[$i + 1]) ? CarbonImmutable::instance($appraisals[$i + 1]['ends'])->startOfDay() : null;
                $closes = $from->addYear();

                if ($next !== null && $next->lt($closes)) {
                    $closes = $next;
                }

                $followed = $promotions->keys()->filter(fn (int $k): bool => $promotions[$k]->gt($from) && $promotions[$k]->lte($closes))->all();
                $credited = [...$credited, ...$followed];
                $promoted = $followed !== [];

                if ($closes->gt($today)) {
                    $counts['promotions_pending'] += count($followed);

                    continue;
                }

                if (! $promoted && $departure->left
                    && ($departure->on === null ? $next === null : $departure->on->lt($closes))) {
                    $counts['left_early']++;

                    continue;
                }

                $features = ['rating_latest' => $appraisal['percent']];
                $previous = $appraisals[$i - 1] ?? null;

                if ($previous !== null) {
                    $features['rating_change'] = round($appraisal['percent'] - $previous['percent'], 2);
                    $counts['with_change']++;
                    $counts['same_form'] += (int) ($previous['form'] === $appraisal['form']);
                }

                $counts[$promoted ? 'promoted' : 'not_promoted']++;
                $counts['promoted_with_change'] += (int) ($promoted && $previous !== null);
                $rows[] = ['group' => (string) $employee->id, 'features' => $features, 'outcome' => (int) $promoted];
            }

            $counts['promotions_unusable'] += $promotions->count() - count(array_unique($credited));
        }

        // Pending promotions were credited too; only those never credited are unusable.
        return new TrainingSet($rows, $counts);
    }

    public function started(TrainingSet $set): bool
    {
        return $set->count('appraisals') > 0;
    }

    public function requirements(TrainingSet $set, FieldCounts $counts): array
    {
        $sameForm = $set->count('with_change') > 0 ? round(100 * $set->count('same_form') / $set->count('with_change'), 1) : 0.0;

        return [
            new Requirement(
                key: 'promoted',
                label: 'Promotions that followed an appraisal',
                group: 'volume',
                current: $set->count('promoted'),
                required: self::MINIMUM_PROMOTED,
                unit: 'promotions',
                unitOne: 'promotion',
                summary: 'Each promotion is an example of who gets promoted here. It counts when a completed appraisal came before it.',
                action: 'Record every promotion on the employee’s profile, with its effective date, and complete appraisals every cycle.',
                basis: 'Proving that a model built from your records is more accurate than the general one takes at least 100 examples of the rarer outcome — here, being promoted (Collins, Ogundimu & Altman, 2016). With fewer, the check can’t tell a better model from a lucky one.',
                source: 'Promotions in employee profiles, each credited to the latest completed appraisal before it. It counts once that appraisal’s follow-up has closed: at the person’s next completed appraisal, or a year later.',
                outlook: Pace::outlook(self::MINIMUM_PROMOTED - (int) $set->count('promoted'), (int) $set->count('recent_promotions'), 'promotions', 'promotion'),
                note: $this->promotionNote($set),
            ),
            new Requirement(
                key: 'promoted_with_change',
                label: 'Promotions with two appraisals before them',
                group: 'volume',
                current: $set->count('promoted_with_change'),
                required: self::MINIMUM_PROMOTED_WITH_CHANGE,
                unit: 'promotions with two appraisals before them',
                unitOne: 'promotion with two appraisals before it',
                summary: 'How much someone improved is the strongest sign of a coming promotion, and it takes two appraisals to see it.',
                action: 'Keep appraising the same people every cycle — the second completed appraisal is what shows improvement.',
                basis: 'Half of the 100 promotions need an earlier appraisal to compare with, so the part of the model that reads improvement learns from at least 50 real examples of it.',
                source: 'The promotions counted above whose employee also had an earlier completed appraisal.',
            ),
            new Requirement(
                key: 'not_promoted',
                label: 'Appraisals not followed by a promotion',
                group: 'volume',
                current: $set->count('not_promoted'),
                required: self::MINIMUM_NOT_PROMOTED,
                unit: 'appraisals not followed by a promotion',
                unitOne: 'appraisal not followed by a promotion',
                summary: 'The model also has to see who was not promoted. Most appraisals end this way, so this fills on its own.',
                action: 'Nothing extra — this grows as appraisals are completed.',
                basis: 'The same check needs at least 100 of each outcome. In most organisations this side fills long before the promotions do.',
                source: 'Completed appraisals whose follow-up closed without a promotion. People who left before it closed are left out: whether they would have been promoted is unknown.',
                derived: true,
            ),
            new Requirement(
                key: 'same_form',
                label: 'Appraisal pairs scored on an unchanged form',
                group: 'quality',
                format: 'percent',
                current: $sameForm,
                required: self::MINIMUM_SAME_FORM,
                summary: 'Improvement only means something when both appraisals were scored on the same form.',
                action: 'Avoid editing an appraisal framework’s sections or rating bands in the middle of the year. If you must, this share dips for a cycle.',
                basis: 'Appraisal frameworks can be edited. A change in score across an edit partly measures the edit, not the person. When at least four in five pairs are like for like, that noise can’t drive what the model learns.',
                source: 'Among appraisals with an earlier one to compare with, the share where both used the same sections and rating bands.',
                derived: true,
                note: $set->count('with_change') === 0 ? 'No one has two completed appraisals yet, so there are no pairs to compare.' : null,
            ),
        ];
    }

    public function fields(FieldCounts $counts): array
    {
        $total = $counts->active();

        return [
            ['key' => 'rating_latest', 'label' => 'Latest completed appraisal', 'source' => 'Performance', 'state' => 'supplied',
                'covered' => $counts->appraised(), 'total' => $total,
                'note' => 'Every readiness score starts here. Employees without a completed appraisal are listed as not assessed, never scored from a guess.'],
            ['key' => 'rating_previous', 'label' => 'Previous completed appraisal', 'source' => 'Performance', 'state' => 'supplied',
                'covered' => $counts->appraised(2), 'total' => $total,
                'note' => 'Gives the change since the previous appraisal — the strongest sign of promotion. Without it, a score says it rests on one appraisal.'],
            ['key' => 'date_hired', 'label' => 'Hire date', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->hired(), 'total' => $total,
                'note' => 'Recorded, but tenure added nothing to readiness once the appraisals are known, so it isn’t used.'],
            ['key' => 'employment_type', 'label' => 'Employment type', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->typed(), 'total' => $total,
                'note' => 'Recorded, but it had no effect on promotion in the data the general model learned from.'],
            ['key' => 'department', 'label' => 'Department', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->inDepartment(), 'total' => $total,
                'note' => 'Deliberately not used: a department’s past promotion rate says nothing about one person’s readiness.'],
            ['key' => 'salary', 'label' => 'Monthly salary', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->salaried(), 'total' => $total,
                'note' => 'Not used: the general model’s salaries are in another currency and period.'],
            ['key' => 'promotion_history', 'label' => 'Promotion history', 'source' => 'Employee profile', 'state' => 'available',
                'covered' => $counts->promoted(), 'total' => $total,
                'note' => 'Deliberately not used as an input: in the general model’s data the recently promoted were promoted again more often — backwards from real practice. It is what your own model learns from.'],
            ['key' => 'certifications', 'label' => 'Certifications', 'source' => 'Employee profile', 'state' => 'available',
                'covered' => $counts->certified(), 'total' => $total,
                'note' => 'Recorded, but they added nothing to readiness once the appraisals are known.'],
            ['key' => 'attendance', 'label' => 'Attendance, last 90 days', 'source' => 'Attendance', 'state' => 'available',
                'covered' => $counts->attendanceTracked(), 'total' => $total,
                'note' => 'Recorded daily, but it had no real bearing on promotion, so it isn’t used.'],
            ['key' => 'overtime', 'label' => 'Overtime, last 90 days', 'source' => 'Attendance', 'state' => 'available',
                'covered' => $counts->attendanceTracked(), 'total' => $total,
                'note' => 'Deliberately not used: overtime depends on role and policy, and a score that rises with hours worked penalises part-time staff and carers.'],
            ['key' => 'training', 'label' => 'Trainings completed, last 12 months', 'source' => 'Training', 'state' => 'available',
                'covered' => $counts->trained(), 'total' => $total,
                'note' => 'Tracked, but it had no bearing on promotion in the data the general model learned from.'],
            ['key' => 'peer_feedback', 'label' => 'Peer feedback', 'source' => 'Not collected', 'state' => 'missing',
                'covered' => 0, 'total' => $total,
                'note' => 'No part of the system collects peer or 360-degree feedback.'],
        ];
    }

    private function promotionNote(TrainingSet $set): ?string
    {
        $parts = [];

        if (($unusable = (int) $set->count('promotions_unusable')) > 0) {
            $parts[] = $unusable === 1
                ? '1 promotion on record can’t count: no completed appraisal came in the year before it.'
                : "{$unusable} promotions on record can’t count: no completed appraisal came in the year before them.";
        }

        if (($pending = (int) $set->count('promotions_pending')) > 0) {
            $parts[] = $pending === 1
                ? '1 more will count once the appraisal before it has been followed up (the next appraisal, or a year).'
                : "{$pending} more will count once the appraisals before them have been followed up (the next appraisal, or a year).";
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
