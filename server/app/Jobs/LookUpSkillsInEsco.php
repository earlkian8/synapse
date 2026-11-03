<?php

namespace App\Jobs;

use App\Support\Recruitment\Skills\Esco\EscoSkills;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask ESCO about the skills a posting asks for that the catalogue doesn't
 * know (ADR 0100), so its candidates' fit scores can use the answers.
 *
 * Dispatched by the JobPosting model whenever a posting's skills change,
 * however they changed — the posting form, the assistant — and run once the
 * response has gone, in the same process, like {@see ReadApplicantFiles}:
 * nobody should wait on another service to save a posting. It carries only
 * the skills' names, so no workspace is involved. A skill with a fresh
 * answer is not asked about again, so running it twice changes nothing.
 */
class LookUpSkillsInEsco implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** A posting asks for a handful of skills; anything past this waits for the daily run. */
    public const MAX_TERMS = 30;

    /**
     * @param  list<string>  $skills
     */
    public function __construct(public array $skills) {}

    public function handle(EscoSkills $esco): void
    {
        $esco->learn($this->skills, limit: self::MAX_TERMS);
    }
}
