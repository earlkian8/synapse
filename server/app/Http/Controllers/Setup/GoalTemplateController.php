<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\GoalTemplateRequest;
use App\Models\GoalTemplate;
use App\Support\Hashid;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Performance framework: the goal library (ADR 0073) — wording
 * and targets a goal can start from. Addressed by hashid; restore / force-delete
 * take it as a string. Every write is {@see PerformanceFrameworkWorkflow}.
 */
class GoalTemplateController extends Controller
{
    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function store(GoalTemplateRequest $request): RedirectResponse
    {
        $this->workflow->saveGoalTemplate(null, $request->validated());

        return $this->respond('Goal added to the library.');
    }

    public function update(GoalTemplateRequest $request, GoalTemplate $goalTemplate): RedirectResponse
    {
        $this->workflow->saveGoalTemplate($goalTemplate, $request->validated());

        return $this->respond('Library goal updated.');
    }

    public function destroy(GoalTemplate $goalTemplate): RedirectResponse
    {
        $this->workflow->archiveGoalTemplate($goalTemplate);

        return $this->respond('Library goal archived.');
    }

    public function restore(string $goalTemplate): RedirectResponse
    {
        $this->workflow->restoreGoalTemplate($this->findTrashed($goalTemplate));

        return $this->respond('Library goal restored.');
    }

    public function forceDelete(string $goalTemplate): RedirectResponse
    {
        $this->workflow->forceDeleteGoalTemplate($this->findTrashed($goalTemplate));

        return $this->respond('Library goal permanently deleted.');
    }

    private function findTrashed(string $hashid): GoalTemplate
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return GoalTemplate::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
