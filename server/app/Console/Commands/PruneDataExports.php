<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Support\DataExport\DataExports;
use Illuminate\Console\Command;

/**
 * Hourly housekeeping for Data Export (ADR 0066), across every workspace:
 *
 *  - an archive past its keep-until date ({@see DataExport::RETENTION_DAYS}) is
 *    deleted from the disk, and its row kept as history with the status expired;
 *  - an export queued or building for longer than any build takes
 *    ({@see DataExport::STALE_AFTER_MINUTES}) is marked failed, so a build that
 *    died with its process does not hold up the next one.
 */
class PruneDataExports extends Command
{
    protected $signature = 'data-export:prune';

    protected $description = 'Delete data export archives past their keep-until date and close exports that never finished';

    public function handle(DataExports $exports): int
    {
        ['expired' => $expired, 'failed' => $failed] = $exports->prune();

        $this->info("Deleted {$expired} expired archive(s); closed {$failed} export(s) that never finished.");

        return self::SUCCESS;
    }
}
