<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreOnboardingProgramRequest;
use App\Http\Requests\Onboarding\UpdateOnboardingProgramRequest;
use App\Http\Resources\OnboardingProgramResource;
use App\Models\Department;
use App\Models\OnboardingProgram;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingProgramController extends Controller
{
    /**
     * Manage onboarding programs (templates) and their blueprint tasks.
     */
    public function index(Request $request): Response
    {
        $programs = OnboardingProgram::query()
            ->with(['department:id,name', 'tasks'])
            ->withCount(['tasks', 'cases'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('setup/onboarding', [
            'programs' => OnboardingProgramResource::collection($programs)->resolve($request),
            'options' => ['departments' => Department::orderBy('name')->get(['id', 'name'])],
            'can' => ['managePrograms' => $request->user()->can('onboarding.manage-programs')],
        ]);
    }

    /**
     * Create a program together with its blueprint tasks.
     */
    public function store(StoreOnboardingProgramRequest $request): RedirectResponse
    {
        $program = DB::transaction(function () use ($request): OnboardingProgram {
            $program = OnboardingProgram::create($this->programAttributes($request));
            $this->enforceSingleDefault($program);
            $this->syncTasks($program, $request->input('tasks', []));

            return $program;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Created onboarding program \"{$program->name}\"",
            subject: $program,
            logName: 'onboarding',
            subjectLabel: $program->name,
        );

        return $this->respond('Program created.');
    }

    /**
     * Update a program and replace its blueprint tasks.
     */
    public function update(UpdateOnboardingProgramRequest $request, OnboardingProgram $program): RedirectResponse
    {
        DB::transaction(function () use ($request, $program): void {
            $program->update($this->programAttributes($request));
            $this->enforceSingleDefault($program);
            $this->syncTasks($program, $request->input('tasks', []));
        });

        ActivityLogger::log(
            event: 'updated',
            description: "Updated onboarding program \"{$program->name}\"",
            subject: $program,
            logName: 'onboarding',
            subjectLabel: $program->name,
        );

        return $this->respond('Program updated.');
    }

    /**
     * Delete a program. In-flight cases keep their already-instantiated tasks.
     */
    public function destroy(OnboardingProgram $program): RedirectResponse
    {
        $name = $program->name;
        $program->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted onboarding program \"{$name}\"",
            logName: 'onboarding',
            subjectLabel: $name,
        );

        return $this->respond('Program deleted.');
    }

    /**
     * The program's own attributes (without the nested task list).
     *
     * @return array<string, mixed>
     */
    private function programAttributes(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'department_id' => $request->input('department_id'),
            'employment_type' => $request->input('employment_type'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Keep at most one default program per tenant.
     */
    private function enforceSingleDefault(OnboardingProgram $program): void
    {
        if ($program->is_default) {
            OnboardingProgram::whereKeyNot($program->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    /**
     * Replace a program's blueprint tasks wholesale (they carry no history).
     *
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function syncTasks(OnboardingProgram $program, array $tasks): void
    {
        $program->tasks()->delete();

        foreach (array_values($tasks) as $index => $task) {
            $program->tasks()->create([
                'title' => $task['title'],
                'description' => $task['description'] ?? null,
                'category' => $task['category'],
                'due_offset_days' => $task['due_offset_days'],
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
