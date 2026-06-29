<?php

namespace App\Console\Commands;

use App\Models\JobPosting;
use Illuminate\Console\Command;

/**
 * Daily housekeeping: close job postings whose closing date has passed.
 *
 * An open posting with a deadline in the past should no longer accept
 * applications. The careers surface already refuses expired postings on the fly
 * (see {@see App\Http\Controllers\Public\CareersController}); this command makes
 * the state durable by flipping them to "closed". It runs system-wide — no tenant
 * is bound on the console, so the organisation scope is a no-op and every
 * tenant's expired postings are swept in one pass.
 */
class CloseExpiredPostings extends Command
{
    protected $signature = 'recruitment:close-expired';

    protected $description = 'Close open job postings whose closing date has passed';

    public function handle(): int
    {
        $closed = JobPosting::query()->expired()->update(['status' => 'closed']);

        $this->info("Closed {$closed} expired job posting(s).");

        return self::SUCCESS;
    }
}
