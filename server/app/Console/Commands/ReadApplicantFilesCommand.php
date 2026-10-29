<?php

namespace App\Console\Commands;

use App\Jobs\ReadApplicantFiles;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read the résumés and documents still waiting (ADR 0097): those on file before
 * the ATS read files at all, and any whose read after upload never finished.
 *
 * Hourly, in batches, so a backlog is worked off without one run taking all
 * night. A file is only picked up once it has waited a few minutes, which
 * leaves fresh uploads to the read that follows their own request. `--again`
 * marks every file for reading again — after installing OCR, say — and reads
 * the first batch.
 *
 * Runs system-wide like {@see CloseExpiredPostings}: no tenant is bound on the
 * console, and the job binds each candidate's own.
 */
class ReadApplicantFilesCommand extends Command
{
    protected $signature = 'recruitment:read-files
        {--limit=100 : How many candidates to read in this run}
        {--again : Read every résumé and document again}';

    protected $description = 'Read the text of candidates’ résumés and documents for the fit score';

    /** How long a fresh upload is left to the read its own request started. */
    private const GRACE_MINUTES = 5;

    public function handle(): int
    {
        if ($this->option('again')) {
            Applicant::query()->whereNotNull('resume')->toBase()->update(['resume_read_status' => Applicant::READ_PENDING]);
            ApplicantDocument::query()->whereNotIn('type', ApplicantDocument::UNREAD_TYPES)->toBase()->update(['read_status' => ApplicantDocument::READ_PENDING]);
        }

        $before = now()->subMinutes(self::GRACE_MINUTES);
        $grace = fn (Builder $query, string $column) => $this->option('again') ? $query : $query->where($column, '<=', $before);

        $applicants = Applicant::query()
            ->where(function (Builder $query) use ($grace): void {
                $query->where(fn (Builder $q) => $grace($q->where('resume_read_status', Applicant::READ_PENDING)->whereNotNull('resume'), 'updated_at'))
                    ->orWhereHas('documents', fn (Builder $q) => $grace($q->where('read_status', ApplicantDocument::READ_PENDING), 'created_at'));
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'organization_id']);

        foreach ($applicants as $applicant) {
            ReadApplicantFiles::dispatchSync($applicant->organization_id, $applicant->id);
        }

        $this->info("Read the files of {$applicants->count()} candidate(s).");

        return self::SUCCESS;
    }
}
