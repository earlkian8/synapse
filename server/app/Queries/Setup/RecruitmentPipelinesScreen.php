<?php

namespace App\Queries\Setup;

use App\Http\Resources\RecruitmentPipelineResource;
use App\Models\RecruitmentPipeline;
use Illuminate\Http\Request;

/**
 * Company Setup → Recruitment Pipelines (ADR 0029): the named, ordered hiring
 * processes a job posting can run on.
 */
class RecruitmentPipelinesScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        $pipelines = RecruitmentPipeline::query()
            ->with('stages')
            ->withCount('postings')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'pipelines' => RecruitmentPipelineResource::collection($pipelines)->resolve($request),
            'can' => ['configure' => $request->user()->can('recruitment.configure-pipelines')],
        ];
    }
}
