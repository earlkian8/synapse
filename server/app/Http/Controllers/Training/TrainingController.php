<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingProgramResource;
use App\Models\Employee;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use App\Support\Training\TrainingInsights;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training & Development — the live view of the organisation's training program: an
 * overview of every program with its schedule, seat usage and completion count,
 * and a program's roster of enrolled employees plus its effectiveness analytics
 * and on-demand AI read. Programs are created in this module (there is no
 * Company-Setup config). Programs are addressed by hashid.
 */
class TrainingController extends Controller
{
    /**
     * The training overview: KPIs + a card per program.
     */
    public function index(Request $request): Response
    {
        $programs = $this->withCounts(TrainingProgram::query())->recentFirst()->get();
        $archived = $this->withCounts(TrainingProgram::query()->onlyTrashed())->recentFirst()->get();

        return Inertia::render('training/index', [
            'programs' => TrainingProgramResource::collection($programs)->resolve($request),
            'archived' => TrainingProgramResource::collection($archived)->resolve($request),
            'stats' => $this->stats($programs),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * A single program with its enrolled employees, the employees still available
     * to enroll, effectiveness analytics and any saved AI read.
     */
    public function show(Request $request, TrainingProgram $trainingProgram, TrainingInsights $insights): Response
    {
        $trainingProgram->loadCount([
            'enrollments',
            'enrollments as active_enrollments_count' => fn (Builder $query) => $query->active(),
            'enrollments as completed_enrollments_count' => fn (Builder $query) => $query->where('status', 'completed'),
        ])->load([
            'enrollments' => fn ($query) => $query->orderByRaw("status = 'dropped'")->latest('id'),
            'enrollments.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'enrollments.employee.department:id,name',
            'enrollments.employee.position:id,title',
        ]);

        return Inertia::render('training/show', [
            'program' => (new TrainingProgramResource($trainingProgram))->resolve($request),
            'enrollable' => $this->enrollableEmployees($trainingProgram),
            'analytics' => $this->analytics($trainingProgram),
            'ai_available' => $insights->enabled(),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Generate (and persist) the LLM effectiveness read for one program. Mirrors
     * the performance / recruitment insights endpoints: on-demand, cached on the
     * model, never a thrown error — failures resolve to an "unavailable" payload.
     */
    public function insights(TrainingProgram $trainingProgram, TrainingInsights $insights): JsonResponse
    {
        $trainingProgram->load([
            'enrollments.employee:id,first_name,middle_name,last_name,suffix',
        ]);

        $result = $insights->generate(
            $trainingProgram,
            $this->analytics($trainingProgram),
            $this->rosterDigest($trainingProgram),
        );

        if ($result['available'] ?? false) {
            $trainingProgram->ai_insights = $result;
            $trainingProgram->save();

            ActivityLogger::log(
                event: 'updated',
                description: "Generated AI training insights for \"{$trainingProgram->name}\"",
                subject: $trainingProgram,
                logName: 'training',
                subjectLabel: $trainingProgram->name,
            );
        }

        return response()->json(['insights' => $result]);
    }

    /**
     * Attach the enrollment aggregates the cards + cost figures read.
     *
     * @param  Builder<TrainingProgram>  $query
     * @return Builder<TrainingProgram>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount('enrollments')
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->withCount(['enrollments as completed_enrollments_count' => fn (Builder $q) => $q->where('status', 'completed')]);
    }

    /**
     * Active employees not yet enrolled in this program, for the enroll picker —
     * each with their department so the picker can group / filter by team.
     *
     * @return list<array{id: int, full_name: string, employee_no: string, department: string|null}>
     */
    private function enrollableEmployees(TrainingProgram $program): array
    {
        $enrolled = $program->enrollments()->pluck('employee_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $enrolled)
            ->with('department:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department?->name,
            ])
            ->all();
    }

    /**
     * Effectiveness analytics for one program, derived from its loaded roster:
     * outcome counts, completion rate, average score and the at-risk headcount
     * (still enrolled after the program has ended).
     *
     * @return array<string, int|float|null>
     */
    private function analytics(TrainingProgram $program): array
    {
        $enrollments = $program->enrollments;
        $total = $enrollments->count();

        $completed = $enrollments->where('status', 'completed')->count();
        $dropped = $enrollments->where('status', 'dropped')->count();
        $enrolled = $enrollments->where('status', 'enrolled')->count();

        $scored = $enrollments->whereNotNull('score');
        $hasEnded = $program->status() === 'completed';

        return [
            'total' => $total,
            'completed' => $completed,
            'dropped' => $dropped,
            'enrolled' => $enrolled,
            'completion_rate' => $total === 0 ? null : (int) round($completed / $total * 100),
            'average_score' => $scored->isEmpty()
                ? null
                : round((float) $scored->avg(fn ($e): float => (float) $e->score), 1),
            'at_risk' => $hasEnded ? $enrolled : 0,
        ];
    }

    /**
     * A flat participant list (name / status / score) for the LLM digest.
     *
     * @return list<array{name: string, status: string, score: float|null}>
     */
    private function rosterDigest(TrainingProgram $program): array
    {
        return $program->enrollments
            ->map(fn ($enrollment): array => [
                'name' => $enrollment->employee?->full_name ?? 'Unknown',
                'status' => $enrollment->status,
                'score' => $enrollment->score === null ? null : (float) $enrollment->score,
            ])
            ->values()
            ->all();
    }

    /**
     * Headline training metrics, derived from the program collection.
     *
     * @param  Collection<int, TrainingProgram>  $programs
     * @return array<string, int>
     */
    private function stats($programs): array
    {
        return [
            'programs' => $programs->count(),
            'ongoing' => $programs->filter(fn (TrainingProgram $p): bool => $p->status() === 'ongoing')->count(),
            'upcoming' => $programs->filter(fn (TrainingProgram $p): bool => $p->status() === 'upcoming')->count(),
            'enrolled' => (int) $programs->sum('active_enrollments_count'),
            'completed' => (int) $programs->sum('completed_enrollments_count'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('training.manage')];
    }
}
