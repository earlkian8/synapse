<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingProgramResource;
use App\Models\Employee;
use App\Models\TrainingProgram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training & Development — the live view of the organisation's training program: an
 * overview of every program with its schedule, seat usage and completion count,
 * and a program's roster of enrolled employees. Programs are created in this module
 * (there is no Company-Setup config). Programs are addressed by hashid.
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
     * A single program with its enrolled employees and the employees still
     * available to enroll.
     */
    public function show(Request $request, TrainingProgram $trainingProgram): Response
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
            'can' => $this->permissions($request),
        ]);
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
     * Active employees not yet enrolled in this program, for the enroll picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function enrollableEmployees(TrainingProgram $program): array
    {
        $enrolled = $program->enrollments()->pluck('employee_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $enrolled)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
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
