<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreRecruitmentPipelineRequest;
use App\Http\Requests\Recruitment\UpdateRecruitmentPipelineRequest;
use App\Models\RecruitmentPipeline;
use App\Queries\Setup\RecruitmentPipelinesScreen;
use App\Support\Recruitment\PipelineException;
use App\Support\Recruitment\PipelineWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup: the hiring processes an organisation can assign to a job
 * posting — a named, ordered list of stages (see ADR 0029). Every organisation
 * that predates this feature already has one ("Standard Hiring," seeded by
 * migration); new organisations start with none and pick or template one here.
 *
 * Thin controller: every write is {@see PipelineWorkflow}, which the assistant
 * uses too, and a refusal comes back as its own message.
 */
class RecruitmentPipelineController extends Controller
{
    public function __construct(private readonly PipelineWorkflow $workflow) {}

    public function index(Request $request, RecruitmentPipelinesScreen $screen): Response
    {
        return Inertia::render('setup/recruitment-pipelines', $screen->toArray($request));
    }

    /**
     * Create a pipeline together with its stages.
     */
    public function store(StoreRecruitmentPipelineRequest $request): RedirectResponse
    {
        $this->workflow->create(
            $request->string('name')->toString(),
            $request->boolean('is_default'),
            $this->stages($request),
        );

        return $this->respond('Pipeline created.');
    }

    /**
     * Update a pipeline and replace its stages — a kept stage carries its id. A
     * stage dropped while candidates sit on it is refused.
     */
    public function update(UpdateRecruitmentPipelineRequest $request, RecruitmentPipeline $pipeline): RedirectResponse
    {
        try {
            $this->workflow->update(
                $pipeline,
                $request->string('name')->toString(),
                $request->boolean('is_default'),
                $this->stages($request),
            );
        } catch (PipelineException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Pipeline updated.');
    }

    /**
     * Delete a pipeline. Refused while any posting still uses it — a posting
     * always needs a pipeline to run its board on.
     */
    public function destroy(RecruitmentPipeline $pipeline): RedirectResponse
    {
        try {
            $this->workflow->delete($pipeline);
        } catch (PipelineException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Pipeline deleted.');
    }

    /**
     * The validated stages in the order they were sent. `validated()` rebuilds
     * the list rule by rule, so a new stage (no `id`) would otherwise come after
     * every kept one — and the order is the pipeline.
     *
     * @return list<array<string, mixed>>
     */
    private function stages(StoreRecruitmentPipelineRequest $request): array
    {
        $stages = (array) $request->validated('stages');
        ksort($stages);

        return array_values($stages);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
