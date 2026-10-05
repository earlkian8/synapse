<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Support\DataExport\DataExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Write the archive of a requested Data Export (ADR 0066). Dispatched by
 * {@see DataExports::request()} to run once the response has gone, in the same
 * process, so it needs no queue worker; a deployment that does queue it loses
 * nothing, because the build claims the export before writing and a second run
 * finds nothing left to do.
 *
 * Read without the tenant scope: the export names its organisation, and the
 * build binds that one itself.
 */
class BuildDataExport implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** A failed build is recorded and reported; retrying it would only repeat it. */
    public int $tries = 1;

    public function __construct(public int $exportId) {}

    public function handle(DataExports $exports): void
    {
        $export = DataExport::withoutGlobalScopes()->find($this->exportId);

        if ($export !== null) {
            $exports->build($export);
        }
    }
}
