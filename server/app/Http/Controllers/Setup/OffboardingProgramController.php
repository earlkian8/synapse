<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Onboarding\OnboardingProgramController;
use App\Http\Requests\Offboarding\OffboardingProgramRequest;
use App\Models\OffboardingProgram;
use App\Queries\Setup\OffboardingProgramsScreen;
use App\Support\Offboarding\OffboardingProgramWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage offboarding programs (clearance templates) and their blueprint sign-off
 * items — the exit-side mirror of {@see OnboardingProgramController}.
 * Configured under Company Setup; instantiated by the Offboarding module when an
 * exit is started. Addressed by hashid. Thin controller: every write is
 * {@see OffboardingProgramWorkflow}, which the assistant uses too.
 */
class OffboardingProgramController extends Controller
{
    public function __construct(private readonly OffboardingProgramWorkflow $workflow) {}

    /**
     * Manage clearance templates and their blueprint items.
     */
    public function index(Request $request, OffboardingProgramsScreen $screen): Response
    {
        return Inertia::render('setup/offboarding', $screen->toArray($request));
    }

    /**
     * Create a template together with its blueprint items.
     */
    public function store(OffboardingProgramRequest $request): RedirectResponse
    {
        $this->workflow->create($this->programAttributes($request), $request->validated('items') ?? []);

        return $this->respond('Template created.');
    }

    /**
     * Update a template and replace its blueprint items.
     */
    public function update(OffboardingProgramRequest $request, OffboardingProgram $program): RedirectResponse
    {
        $this->workflow->update($program, $this->programAttributes($request), $request->validated('items') ?? []);

        return $this->respond('Template updated.');
    }

    /**
     * Delete a template. In-flight cases keep their already-instantiated items.
     */
    public function destroy(OffboardingProgram $program): RedirectResponse
    {
        $this->workflow->delete($program);

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

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
