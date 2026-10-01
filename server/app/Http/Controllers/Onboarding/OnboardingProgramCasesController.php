<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\OnboardingCaseResource;
use App\Models\Department;
use App\Models\OnboardingProgram;
use App\Queries\OnboardingCasesIndexQuery;
use App\Queries\OnboardingStatistics;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people one onboarding program is onboarding — the second level of the
 * module, between the programs overview and a person's own checklist. Cases that
 * run on no program have the same page under "Unassigned". Reading needs
 * `onboarding.view`; the actions on each row are the case routes' own.
 */
class OnboardingProgramCasesController extends Controller
{
    public function show(Request $request, OnboardingProgram $program, OnboardingCasesIndexQuery $query, OnboardingStatistics $statistics): Response
    {
        $program->load('department:id,name')->loadCount('tasks');

        return $this->render($request, $query, $statistics, [
            'id' => $program->id,
            'hashid' => $program->hashid,
            'name' => $program->name,
            'description' => $program->description,
            'department' => $program->department ? ['id' => $program->department->id, 'name' => $program->department->name] : null,
            'employment_type' => $program->employment_type,
            'is_default' => $program->is_default,
            'is_active' => $program->is_active,
            'tasks_count' => (int) $program->tasks_count,
        ], fn (Builder $cases) => $cases->where('onboarding_program_id', $program->id));
    }

    /**
     * Cases started without a program, or whose program was since deleted.
     */
    public function unassigned(Request $request, OnboardingCasesIndexQuery $query, OnboardingStatistics $statistics): Response
    {
        return $this->render($request, $query, $statistics, null, fn (Builder $cases) => $cases->whereNull('onboarding_program_id'));
    }

    /**
     * @param  array<string, mixed>|null  $program
     * @param  Closure(Builder): void  $scope
     */
    private function render(Request $request, OnboardingCasesIndexQuery $query, OnboardingStatistics $statistics, ?array $program, Closure $scope): Response
    {
        return Inertia::render('onboarding/program', [
            'program' => $program,
            'cases' => OnboardingCaseResource::collection($query->paginate($request, $scope)),
            'stats' => $statistics->toArray($scope),
            'options' => [
                ...OnboardingCaseController::startOptions(),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
            ],
            'can' => OnboardingCaseController::permissions($request),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request, 'all'),
                'department' => $request->integer('department') ?: null,
                'sort' => $query->sort($request),
                'direction' => $query->direction($request),
                'per_page' => $query->perPage($request),
            ],
        ]);
    }
}
