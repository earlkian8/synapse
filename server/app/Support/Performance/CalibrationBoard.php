<?php

namespace App\Support\Performance;

use App\Models\CalibrationAdjustment;
use App\Models\CalibrationSession;
use App\Models\PerformanceEvaluation;
use Illuminate\Support\Collection;

/**
 * What a calibration session's room looks at (ADR 0073): every appraisal in its
 * scope with what the scorecard gave and what it is rated now, and the spread
 * across the bands **before and after** the session's moves — the read that
 * shows whether calibration changed the shape of the cycle, not just one card.
 */
class CalibrationBoard
{
    public function __construct(private readonly PerformanceCalibration $calibration) {}

    /**
     * @return array{rows: list<array<string, mixed>>, spread: list<array<string, mixed>>, departments: list<array<string, mixed>>, average: float|null, counts: array<string, int>}
     */
    public function for(CalibrationSession $session): array
    {
        $evaluations = $session->evaluations()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,photo,department_id,position_id,user_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'evaluator:id,first_name,last_name',
            ])
            ->get()
            ->sortBy(fn (PerformanceEvaluation $e): string => ($e->employee?->department?->name ?? '~').'|'.($e->employee?->full_name ?? ''))
            ->values();

        $latest = CalibrationAdjustment::query()
            ->whereIn('performance_evaluation_id', $evaluations->pluck('id'))
            ->with(['session:id,name', 'adjuster:id,first_name,last_name'])
            ->orderBy('id')
            ->get()
            ->keyBy('performance_evaluation_id');

        $completed = $evaluations->filter(fn (PerformanceEvaluation $e): bool => $e->status !== 'draft' && $e->result_label !== null);

        return [
            'rows' => $evaluations->map(fn (PerformanceEvaluation $e): array => $this->row($e, $latest->get($e->id)))->all(),
            'spread' => $this->spread($completed),
            'departments' => $this->calibration->byDepartment($evaluations),
            'average' => $completed->isEmpty() ? null : round((float) $completed->avg(fn (PerformanceEvaluation $e): float => (float) $e->overall_percent), 1),
            'counts' => [
                'total' => $evaluations->count(),
                'draft' => $evaluations->where('status', 'draft')->count(),
                'submitted' => $evaluations->where('status', 'submitted')->count(),
                'acknowledged' => $evaluations->where('status', 'acknowledged')->count(),
                'moved' => $evaluations->filter(fn (PerformanceEvaluation $e): bool => $e->isCalibrated())->count(),
                'held' => $evaluations->filter(fn (PerformanceEvaluation $e): bool => $e->status === 'submitted' && $e->shared_at === null)->count(),
            ],
        ];
    }

    /**
     * One appraisal as the room sees it.
     *
     * @return array<string, mixed>
     */
    private function row(PerformanceEvaluation $evaluation, ?CalibrationAdjustment $last): array
    {
        $bands = $evaluation->bandList();
        $band = fn (?string $key): ?array => $key === null ? null : collect($bands)->firstWhere('key', $key);

        return [
            'id' => $evaluation->id,
            'hashid' => $evaluation->hashid,
            'status' => $evaluation->status,
            'held' => $evaluation->status === 'submitted' && $evaluation->shared_at === null,
            'overall_percent' => $evaluation->overall_percent === null ? null : (float) $evaluation->overall_percent,
            'template_name' => $evaluation->template_name,
            'bands' => $bands,
            'scored' => $band($evaluation->scoredBandKey()),
            'current' => $band($evaluation->result_band),
            'calibrated' => $evaluation->isCalibrated(),
            'last_adjustment' => $last ? [
                'reason' => $last->reason,
                'by' => $last->adjuster?->full_name,
                'session' => $last->session?->name,
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'evaluator' => $evaluation->evaluator?->full_name,
            'employee' => $evaluation->employee ? [
                'id' => $evaluation->employee->id,
                'user_id' => $evaluation->employee->user_id,
                'full_name' => $evaluation->employee->full_name,
                'initials' => $evaluation->employee->initials(),
                'photo' => $evaluation->employee->photo_url,
                'department' => $evaluation->employee->department?->name,
                'position' => $evaluation->employee->position?->title,
            ] : null,
        ];
    }

    /**
     * How many sat in each band before the session's moves (what the scorecards
     * gave) and after them (what they are rated now), by the band's words —
     * highest cut first.
     *
     * @param  Collection<int, PerformanceEvaluation>  $completed
     * @return list<array{label: string, tone: string, min_percent: float, before: int, after: int}>
     */
    private function spread(Collection $completed): array
    {
        $rows = [];

        foreach ($completed as $evaluation) {
            $bands = collect($evaluation->bandList());

            foreach (['before' => $evaluation->scoredBandKey(), 'after' => $evaluation->result_band] as $when => $key) {
                $band = $bands->firstWhere('key', $key);

                if ($band === null) {
                    continue;
                }

                $rows[$band['label']] ??= ['label' => $band['label'], 'tone' => $band['tone'], 'min_percent' => (float) $band['min_percent'], 'before' => 0, 'after' => 0];
                $rows[$band['label']][$when]++;
                $rows[$band['label']]['min_percent'] = max($rows[$band['label']]['min_percent'], (float) $band['min_percent']);
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);

        return $rows;
    }
}
