<?php

namespace App\Support\Recruitment;

use App\Http\Controllers\Recruitment\InterviewController;
use App\Models\Interview;
use App\Models\JobApplication;

/**
 * The one path an interview is booked, moved or called through. Extracted from
 * {@see InterviewController} so the pipeline board and the assistant share the
 * same side effects — most importantly that booking an interview *pulls the
 * candidate into the interview stage* instead of leaving the board lying.
 */
class InterviewScheduler
{
    /** Stages that a booked interview advances out of. */
    private const ADVANCES_FROM = ['applied', 'screening'];

    /** The outcomes an interview can be closed with. */
    public const RESULTS = ['pending', 'passed', 'failed'];

    /**
     * Book an interview for an application and advance an early-stage candidate
     * into the interview stage.
     *
     * @param  array<string, mixed>  $data  Validated interview attributes.
     */
    public static function book(JobApplication $application, array $data): Interview
    {
        $interview = $application->interviews()->create($data);

        if (in_array($application->stage, self::ADVANCES_FROM, true)) {
            $application->moveTo('interview');
        }

        return $interview;
    }
}
