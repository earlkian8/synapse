<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Onboarding\OnboardingProgramController;
use App\Http\Requests\Offboarding\OffboardingProgramRequest;
use App\Http\Resources\OffboardingProgramResource;
use App\Models\Department;
use App\Models\OffboardingProgram;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage offboarding programs (clearance templates) and their blueprint sign-off
 * items — the exit-side mirror of {@see OnboardingProgramController}.
 * Configured under Company Setup; instantiated by the Offboarding module when an
 * exit is started. Addressed by hashid.
 */
class OffboardingProgramController extends Controller
{
    /**
     * Manage clearance templates and their blueprint items.
     */
    public function index(Request $request): Response
    {
        $programs = OffboardingProgram::query()
            ->with(['department:id,name', 'items', 'items.department:id,name'])
            ->withCount(['items', 'cases'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('setup/offboarding', [
            'programs' => OffboardingProgramResource::collection($programs)->resolve($request),
            'options' => ['departments' => Department::orderBy('name')->get(['id', 'name'])],
            'can' => ['managePrograms' => $request->user()->can('offboarding.manage-programs')],
        ]);
    }

    /**
     * Create a template together with its blueprint items.
     */
    public function store(OffboardingProgramRequest $request): RedirectResponse
    {
        $program = DB::transaction(function () use ($request): OffboardingProgram {
            $program = OffboardingProgram::create($this->programAttributes($request));
            $this->enforceSingleDefault($program);
            $this->syncItems($program, $request->input('items', []));

            return $program;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Created clearance template \"{$program->name}\"",
            subject: $program,
            logName: 'offboarding',
            subjectLabel: $program->name,
        );

        return $this->respond('Template created.');
    }

    /**
     * Update a template and replace its blueprint items.
     */
    public function update(OffboardingProgramRequest $request, OffboardingProgram $program): RedirectResponse
    {
        DB::transaction(function () use ($request, $program): void {
            $program->update($this->programAttributes($request));
            $this->enforceSingleDefault($program);
            $this->syncItems($program, $request->input('items', []));
        });

        ActivityLogger::log(
            event: 'updated',
            description: "Updated clearance template \"{$program->name}\"",
            subject: $program,
            logName: 'offboarding',
            subjectLabel: $program->name,
        );

        return $this->respond('Template updated.');
    }

    /**
     * Delete a template. In-flight cases keep their already-instantiated items.
     */
    public function destroy(OffboardingProgram $program): RedirectResponse
    {
        $name = $program->name;
        $program->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted clearance template \"{$name}\"",
            logName: 'offboarding',
            subjectLabel: $name,
        );

        return $this->respond('Template deleted.');
    }

    /**
     * The program's own attributes (without the nested item list).
     *
     * @return array<string, mixed>
     */
    private function programAttributes(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'department_id' => $request->input('department_id'),
            'exit_type' => $request->input('exit_type'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Keep at most one default template per tenant.
     */
    private function enforceSingleDefault(OffboardingProgram $program): void
    {
        if ($program->is_default) {
            OffboardingProgram::whereKeyNot($program->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    /**
     * Replace a program's blueprint items wholesale (they carry no history).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(OffboardingProgram $program, array $items): void
    {
        $program->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $program->items()->create([
                'item' => $item['item'],
                'department_id' => ($item['use_employee_department'] ?? false) ? null : ($item['department_id'] ?? null),
                'use_employee_department' => (bool) ($item['use_employee_department'] ?? false),
                'sort_order' => $index,
            ]);
        }
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
