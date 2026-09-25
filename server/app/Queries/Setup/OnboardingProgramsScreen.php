<?php

namespace App\Queries\Setup;

use App\Http\Resources\OnboardingProgramResource;
use App\Models\Department;
use App\Models\OnboardingProgram;
use Illuminate\Http\Request;

/**
 * Company Setup → Onboarding Programs: the reusable checklists that seed each new
 * hire's onboarding.
 */
class OnboardingProgramsScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        $programs = OnboardingProgram::query()
            ->with(['department:id,name', 'tasks'])
            ->withCount(['tasks', 'cases'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'programs' => OnboardingProgramResource::collection($programs)->resolve($request),
            'options' => ['departments' => Department::orderBy('name')->get(['id', 'name'])],
            'can' => ['managePrograms' => $request->user()->can('onboarding.manage-programs')],
        ];
    }
}
