<?php

namespace App\Queries\Setup;

use App\Http\Resources\OffboardingProgramResource;
use App\Models\Department;
use App\Models\OffboardingProgram;
use Illuminate\Http\Request;

/**
 * Company Setup → Offboarding Programs: the reusable clearance templates that
 * seed each exit's checklist.
 */
class OffboardingProgramsScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        $programs = OffboardingProgram::query()
            ->with(['department:id,name', 'items', 'items.department:id,name'])
            ->withCount(['items', 'cases'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'programs' => OffboardingProgramResource::collection($programs)->resolve($request),
            'options' => ['departments' => Department::orderBy('name')->get(['id', 'name'])],
            'can' => ['managePrograms' => $request->user()->can('offboarding.manage-programs')],
        ];
    }
}
