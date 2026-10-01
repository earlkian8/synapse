<?php

namespace App\Support\Ml\Graduation;

use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Support\Ml\AttritionRiskAssessor;
use Carbon\CarbonImmutable;

/**
 * Attrition Risk's terms of graduation (ADR 0046). Its own model would learn who
 * resigns *here*, from the example the general model reads: an employee's record as
 * it stood when they were scored — exactly as stored on the risk score, since tenure,
 * salary and last quarter's attendance cannot be reconstructed afterwards — and
 * whether they resigned within the following year.
 *
 * **Stored scores are the examples.** Per person, scores are taken at least a year
 * apart (the earliest first), so their follow-up years never overlap and no
 * resignation counts twice. A score is used once its year has passed. Only a
 * **resignation** is what the model predicts (as in the survey the general model
 * learned from); someone dismissed, retired or out of contract within the year is
 * left out — neither a leaver in that sense nor a stayer — and so is anyone who left
 * with no offboarding record, since their departure has no date or type.
 */
class AttritionGraduation implements GraduationSurface
{
    /**
     * Mirrors the inference service's minimums (model/synapse_ml/local/training.py):
     * 100 of each outcome (Collins, Ogundimu & Altman, 2016).
     */
    public const MINIMUM_RESIGNED = 100;

    public const MINIMUM_STAYED = 100;

    /** Share (%) of departures that must carry a recorded type. */
    public const MINIMUM_RECORDED_DEPARTURES = 90;

    public function key(): string
    {
        return 'attrition';
    }

    public function trainingSet(): TrainingSet
    {
        $today = CarbonImmutable::today();
        $employees = Employee::query()
            ->with('offboardingCase:id,employee_id,type,status,last_working_day,completed_at')
            ->get(['id', 'employment_status'])
            ->keyBy('id');

        $scores = AttritionRiskScore::query()
            ->join('attrition_risk_runs', 'attrition_risk_runs.id', '=', 'attrition_risk_scores.attrition_risk_run_id')
            ->whereIn('attrition_risk_scores.employee_id', $employees->keys())
            ->orderBy('attrition_risk_runs.created_at')
            ->get(['attrition_risk_scores.employee_id', 'attrition_risk_scores.features', 'attrition_risk_runs.created_at as scored_at']);

        $rows = [];
        $counts = ['scores' => $scores->count(), 'resigned' => 0, 'stayed' => 0, 'pending' => 0, 'other_exit' => 0,
            'unrecorded_exit' => 0, 'recent_resignations' => 0];

        foreach ($employees as $employee) {
            $departure = Departure::of($employee);

            if ($departure->type === 'resignation' && $departure->on?->gt($today->subYears(2))) {
                $counts['recent_resignations']++;
            }
        }

        foreach ($scores->groupBy('employee_id') as $employeeId => $snapshots) {
            $departure = Departure::of($employees[$employeeId]);
            $after = null;

            foreach ($snapshots as $snapshot) {
                $at = CarbonImmutable::parse($snapshot->scored_at)->startOfDay();

                // Follow-up years never overlap: the next snapshot taken is a year on.
                if ($after !== null && $at->lt($after)) {
                    continue;
                }

                $after = $at->addYear();

                if ($departure->unrecorded()) {
                    $counts['unrecorded_exit']++;

                    continue;
                }

                $leftWithinYear = $departure->left && $departure->on->gt($at) && $departure->on->lte($after);

                if (! $leftWithinYear && $after->gt($today)) {
                    $counts['pending']++;

                    continue;
                }

                if ($leftWithinYear && $departure->type !== 'resignation') {
                    $counts['other_exit']++;

                    continue;
                }

                $resigned = $leftWithinYear;
                $counts[$resigned ? 'resigned' : 'stayed']++;
                $rows[] = [
                    'group' => (string) $employeeId,
                    'features' => array_intersect_key((array) $snapshot->features, array_flip(AttritionRiskAssessor::KEY_FEATURES)),
                    'outcome' => (int) $resigned,
                ];
            }
        }

        return new TrainingSet($rows, $counts);
    }

    public function started(TrainingSet $set): bool
    {
        return $set->count('scores') > 0;
    }

    public function requirements(TrainingSet $set, FieldCounts $counts): array
    {
        $departed = $counts->departed();
        $recorded = $departed > 0 ? round(100 * $counts->departedWithReason() / $departed, 1) : 100.0;

        return [
            new Requirement(
                key: 'resigned',
                label: 'Resignations within a year of a risk score',
                group: 'volume',
                current: $set->count('resigned'),
                required: self::MINIMUM_RESIGNED,
                unit: 'resignations',
                unitOne: 'resignation',
                summary: 'Each resignation that came within a year of a stored risk score is an example of who leaves here.',
                action: 'Run the risk assessment regularly — every few months — and process every departure through Offboarding.',
                basis: 'Proving that a model built from your records is more accurate than the general one takes at least 100 examples of the rarer outcome — here, resigning (Collins, Ogundimu & Altman, 2016). With fewer, the check can’t tell a better model from a lucky one.',
                source: 'Completed offboarding cases of type resignation whose last working day fell within a year after one of the person’s stored risk scores. Each person’s scores are taken at least a year apart, so no resignation counts twice.',
                outlook: Pace::outlook(self::MINIMUM_RESIGNED - (int) $set->count('resigned'), (int) $set->count('recent_resignations'), 'resignations', 'resignation'),
                note: $this->resignationNote($set),
            ),
            new Requirement(
                key: 'stayed',
                label: 'Risk scores followed by a year of staying',
                group: 'volume',
                current: $set->count('stayed'),
                required: self::MINIMUM_STAYED,
                unit: 'risk scores followed by a year of staying',
                unitOne: 'risk score followed by a year of staying',
                summary: 'The model also has to see who stayed. Most people do, so this fills on its own once scores are a year old.',
                action: 'Nothing extra — keep running the risk assessment and this grows as each score’s year passes.',
                basis: 'The same check needs at least 100 of each outcome. This side usually fills first.',
                source: 'Stored risk scores whose following year passed with the person still employed.',
                derived: true,
            ),
            new Requirement(
                key: 'recorded_departures',
                label: 'Departures with a recorded type',
                group: 'quality',
                format: 'percent',
                current: $recorded,
                required: self::MINIMUM_RECORDED_DEPARTURES,
                summary: 'A resignation and a dismissal mean opposite things. Only a recorded departure type tells them apart.',
                action: 'Process every departure through Offboarding and choose its type — resignation, termination, retirement or end of contract.',
                basis: 'Only resignations are what the model learns to predict, so every other exit is set aside. A departure with no type can’t be sorted either way; when more than one in ten lack one, the examples that remain may not represent who actually leaves.',
                source: 'Former employees (resigned or terminated) who left through a completed offboarding case.',
                note: $departed === 0 ? 'No one has left yet, so there is nothing to record.' : null,
            ),
        ];
    }

    public function fields(FieldCounts $counts): array
    {
        $total = $counts->active();
        $tracked = $counts->attendanceTracked();
        $attendance = 'Counted from the daily attendance records. Anyone with no attendance tracked in the window has theirs estimated rather than read, which lowers their confidence.';

        return [
            ['key' => 'employment_type', 'label' => 'Employment type', 'source' => 'Employee record', 'state' => 'supplied',
                'covered' => $counts->typed(), 'total' => $total,
                'note' => 'Regular, probationary, part-time or contractual.'],
            ['key' => 'date_hired', 'label' => 'Hire date (tenure)', 'source' => 'Employee record', 'state' => 'supplied',
                'covered' => $counts->hired(), 'total' => $total,
                'note' => 'Tenure is the strongest single sign of leaving: new hires and people six to ten years in leave most.'],
            ['key' => 'salary', 'label' => 'Monthly salary', 'source' => 'Employee record', 'state' => 'supplied',
                'covered' => $counts->salaried(), 'total' => $total,
                'note' => 'Read in pesos a month, as the survey asked it.'],
            ['key' => 'since_promotion', 'label' => 'Time since last promotion', 'source' => 'Employee profile', 'state' => 'supplied',
                'covered' => $counts->promoted(), 'total' => $total,
                'note' => 'Read from the promotion history. For someone never promoted, the wait is their whole tenure.'],
            ['key' => 'absences', 'label' => 'Absences, last 90 days', 'source' => 'Attendance', 'state' => 'supplied',
                'covered' => $tracked, 'total' => $total,
                'note' => "Approved leave, rest days and holidays are not absences. {$attendance}"],
            ['key' => 'lates', 'label' => 'Late arrivals, last 90 days', 'source' => 'Attendance', 'state' => 'supplied',
                'covered' => $tracked, 'total' => $total, 'note' => $attendance],
            ['key' => 'overtime', 'label' => 'Overtime hours, last 90 days', 'source' => 'Attendance', 'state' => 'supplied',
                'covered' => $tracked, 'total' => $total,
                'note' => 'Hours worked beyond the shift, approved or not — the survey asked how much people worked, not how much was signed off.'],
            ['key' => 'department', 'label' => 'Department', 'source' => 'Employee record', 'state' => 'available',
                'covered' => $counts->inDepartment(), 'total' => $total,
                'note' => 'Recorded, but not used: the survey the general model learned from couldn’t be matched to a department list.'],
            ['key' => 'training', 'label' => 'Trainings completed, last 12 months', 'source' => 'Training', 'state' => 'available',
                'covered' => $counts->trained(), 'total' => $total,
                'note' => 'Tracked, but the survey didn’t ask about training, so the general model can’t use it.'],
            ['key' => 'departure_type', 'label' => 'Departure type', 'source' => 'Offboarding', 'state' => 'available',
                'covered' => $counts->departedWithReason(), 'total' => $counts->departed(),
                'note' => 'Counted against the people who left, not headcount. It is what your own model learns from: only resignations count as leaving.'],
            ['key' => 'engagement', 'label' => 'Engagement and job satisfaction', 'source' => 'Not collected', 'state' => 'missing',
                'covered' => 0, 'total' => $total,
                'note' => 'One of the strongest published signs of leaving, and no part of the system runs a survey that would produce it.'],
            ['key' => 'pay_benchmark', 'label' => 'Pay against market rate', 'source' => 'Not collected', 'state' => 'missing',
                'covered' => 0, 'total' => $total,
                'note' => 'Salary is recorded, but nothing compares it with a market benchmark.'],
        ];
    }

    private function resignationNote(TrainingSet $set): ?string
    {
        $parts = [];

        if (($pending = (int) $set->count('pending')) > 0) {
            $parts[] = $pending === 1
                ? '1 risk score is less than a year old; it counts once its year has passed.'
                : "{$pending} risk scores are less than a year old; they count once their year has passed.";
        }

        if (($unrecorded = (int) $set->count('unrecorded_exit')) > 0) {
            $parts[] = $unrecorded === 1
                ? '1 score belongs to someone who left without an offboarding record, so it can’t count.'
                : "{$unrecorded} scores belong to people who left without an offboarding record, so they can’t count.";
        }

        if ($set->count('scores') === 0) {
            $parts[] = 'No risk scores are stored yet — run an assessment to start.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
