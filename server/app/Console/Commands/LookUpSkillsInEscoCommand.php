<?php

namespace App\Console\Commands;

use App\Models\JobPosting;
use App\Support\Recruitment\Skills\Esco\EscoSkills;
use Illuminate\Console\Command;

/**
 * Ask ESCO about every posting's skills the catalogue doesn't know and that
 * have no fresh answer (ADR 0100): skills on postings from before ESCO was
 * asked at all, lookups that failed while ESCO was unreachable, and answers
 * old enough to ask again. `--again` asks about every one.
 *
 * Daily, and at most `--limit` lookups a run, so a large backlog is worked off
 * over a few nights without leaning on a free service. Runs system-wide like
 * {@see CloseExpiredPostings}: no tenant is bound on the console, and only
 * skills' names are read.
 */
class LookUpSkillsInEscoCommand extends Command
{
    protected $signature = 'recruitment:esco-lookup
        {--limit=200 : How many skills to look up in this run}
        {--again : Ask about every skill again, fresh or not}';

    protected $description = 'Look up in ESCO the posting skills the ATS’s catalogue doesn’t know';

    public function handle(EscoSkills $esco): int
    {
        if (! $esco->enabled()) {
            $this->warn('ESCO is turned off (ESCO_ENABLED).');

            return self::SUCCESS;
        }

        $skills = [];

        JobPosting::query()->select(['id', 'skills', 'preferred_skills'])->chunkById(500, function ($postings) use (&$skills): void {
            foreach ($postings as $posting) {
                array_push($skills, ...($posting->skills ?? []), ...($posting->preferred_skills ?? []));
            }
        });

        $asked = $esco->learn($skills, (bool) $this->option('again'), max(1, (int) $this->option('limit')));

        $this->info("Looked up {$asked} skill(s) in ESCO.");

        return self::SUCCESS;
    }
}
