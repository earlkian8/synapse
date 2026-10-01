<?php

namespace App\Support\Recruitment;

use App\Http\Controllers\Recruitment\InterviewController;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\RecruitmentPipelineStage;

/**
 * The one path an interview is booked, moved or called through. Extracted from
 * {@see InterviewController} so the pipeline board and the assistant share the
 * same side effects — most importantly that booking an interview *pulls the
 * candidate forward* instead of leaving the board lying.
 */
class InterviewScheduler
{
    /** The outcomes an interview can be closed with. */
    public const RESULTS = ['pending', 'passed', 'failed'];

    /**
     * Book an interview for an application. Unless the caller names a specific
     * target stage, defaults to the posting's pipeline's next open stage after
     * the application's current one — the same one-click convenience the old
     * hardcoded "applied/screening → interview" rule gave, generalised to any
     * pipeline. Never moves backward, and never off the application's own
     * pipeline.
     *
     * @param  array<string, mixed>  $data  Validated interview attributes.
     */
    public static function book(JobApplication $application, array $data, ?RecruitmentPipelineStage $targetStage = null): Interview
    {
        $interview = $application->interviews()->create($data);

        if ($application->pipelineStage->kind !== 'open') {
            return $interview;
        }

        $pipeline = $application->jobPosting->pipeline;
        $targetStage ??= $pipeline->nextOpenStageAfter($application->pipelineStage);

        if ($targetStage !== null
            && $targetStage->kind === 'open'
            && $targetStage->recruitment_pipeline_id === $pipeline->id
            && $targetStage->position > $application->pipelineStage->position
        ) {
            $application->moveTo($targetStage);
        }

        return $interview;
    }
}
