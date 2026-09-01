<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreRecruitmentPipelineRequest;
use App\Http\Requests\Recruitment\UpdateRecruitmentPipelineRequest;
use App\Http\Resources\RecruitmentPipelineResource;
use App\Models\RecruitmentPipeline;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Company Setup: the hiring processes an organisation can assign to a job
 * posting — a named, ordered list of stages (see ADR 0029). Every organisation
 * that predates this feature already has one ("Standard Hiring," seeded by
 * migration); new organisations start with none and pick or template one here.
 */
class RecruitmentPipelineController extends Controller
{
    public function index(Request $request): Response
    {
        $pipelines = RecruitmentPipeline::query()
            ->with('stages')
            ->withCount('postings')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('setup/recruitment-pipelines', [
            'pipelines' => RecruitmentPipelineResource::collection($pipelines)->resolve($request),
            'can' => ['configure' => $request->user()->can('recruitment.configure-pipelines')],
        ]);
    }

    /**
     * Create a pipeline together with its stages.
     */
    public function store(StoreRecruitmentPipelineRequest $request): RedirectResponse
    {
        $pipeline = DB::transaction(function () use ($request): RecruitmentPipeline {
            // An organisation's first pipeline is always the default — nothing
            // else could resolve a posting's pipeline otherwise.
            $isFirst = ! RecruitmentPipeline::query()->exists();

            $pipeline = RecruitmentPipeline::create([
                'name' => $request->string('name')->toString(),
                'is_default' => $isFirst || $request->boolean('is_default'),
            ]);
            $pipeline->enforceSingleDefault();
            $pipeline->syncStages($request->input('stages', []));

            return $pipeline;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Created recruitment pipeline \"{$pipeline->name}\"",
            subject: $pipeline,
            logName: 'recruitment',
            subjectLabel: $pipeline->name,
        );

        return $this->respond('Pipeline created.');
    }

    /**
     * Update a pipeline and replace its stages. A stage that's dropped here
     * while applications still sit on it is refused — see
     * {@see RecruitmentPipelineStage} FK (`restrictOnDelete`); the form is
     * expected to warn before that happens.
     */
    public function update(UpdateRecruitmentPipelineRequest $request, RecruitmentPipeline $pipeline): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $pipeline): void {
                $pipeline->update([
                    'name' => $request->string('name')->toString(),
                    'is_default' => $request->boolean('is_default'),
                ]);
                $pipeline->enforceSingleDefault();
                $pipeline->syncStages($request->input('stages', []));
            });
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Updated recruitment pipeline \"{$pipeline->name}\"",
            subject: $pipeline,
            logName: 'recruitment',
            subjectLabel: $pipeline->name,
        );

        return $this->respond('Pipeline updated.');
    }

    /**
     * Delete a pipeline. Refused while any posting still uses it — a posting
     * always needs a pipeline to run its board on.
     */
    public function destroy(RecruitmentPipeline $pipeline): RedirectResponse
    {
        if ($pipeline->postings()->exists()) {
            return $this->respond('This pipeline is in use by one or more job postings and can\'t be deleted.', 'error');
        }

        $name = $pipeline->name;
        $wasDefault = $pipeline->is_default;
        $pipeline->delete();

        // Promote another pipeline to default so postings still have a fallback
        // to resolve to — otherwise nothing names one.
        if ($wasDefault) {
            RecruitmentPipeline::query()->orderBy('name')->first()?->update(['is_default' => true]);
        }

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted recruitment pipeline \"{$name}\"",
            logName: 'recruitment',
            subjectLabel: $name,
        );

        return $this->respond('Pipeline deleted.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
