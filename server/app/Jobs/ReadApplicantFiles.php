<?php

namespace App\Jobs;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Organization;
use App\Support\Files\DocumentText;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read the text of a candidate's résumé and supporting documents (ADR 0097),
 * so the fit score matches their skills against what they actually sent.
 *
 * Dispatched by the Applicant and ApplicantDocument models whenever a file
 * arrives, however it arrived — the careers page, the recruiter's forms, a file
 * sent to the assistant — and by `recruitment:read-files` for files still
 * waiting. Like {@see RecomputeAttendanceRange} it runs once the response has
 * gone, in the same process, because the app is often served without a queue
 * worker; reading a scan by OCR takes seconds nobody should wait for.
 *
 * Only what is `pending` is read, and a result is written only if the file is
 * still the one that was read, so running it twice, or after the résumé was
 * replaced, changes nothing it shouldn't. A government ID is never read.
 */
class ReadApplicantFiles implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $organizationId,
        public int $applicantId,
    ) {}

    public function handle(Tenancy $tenancy, DocumentText $reader): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenancy->runFor($organization, function () use ($reader): void {
            $applicant = Applicant::query()->find($this->applicantId);

            if ($applicant === null) {
                return;
            }

            $this->readResume($applicant, $reader);

            if ($this->readDocuments($applicant, $reader)) {
                $this->collectDocumentText($applicant);
            }
        });
    }

    private function readResume(Applicant $applicant, DocumentText $reader): void
    {
        if ($applicant->resume === null || $applicant->resume_read_status !== Applicant::READ_PENDING) {
            return;
        }

        $path = $applicant->resume;
        $text = $reader->fromUpload($path);

        // Written by query, so it touches nothing else on the row — not even
        // updated_at — and only while this is still the résumé that was read.
        Applicant::query()
            ->whereKey($applicant->id)
            ->where('resume', $path)
            ->toBase()
            ->update([
                'resume_text' => filled($text) ? $text : null,
                'resume_read_status' => filled($text) ? Applicant::READ_DONE : Applicant::READ_UNREADABLE,
            ]);
    }

    /**
     * Read every pending document. Returns whether any was read.
     */
    private function readDocuments(Applicant $applicant, DocumentText $reader): bool
    {
        $pending = $applicant->documents()->where('read_status', ApplicantDocument::READ_PENDING)->get();

        foreach ($pending as $document) {
            $text = $reader->fromUpload($document->file);

            ApplicantDocument::query()
                ->whereKey($document->id)
                ->toBase()
                ->update([
                    'extracted_text' => filled($text) ? $text : null,
                    'read_status' => filled($text) ? ApplicantDocument::READ_DONE : ApplicantDocument::READ_UNREADABLE,
                ]);
        }

        return $pending->isNotEmpty();
    }

    /**
     * Keep the supporting documents' text together on the applicant, so the
     * fit score never has to load them.
     */
    private function collectDocumentText(Applicant $applicant): void
    {
        $text = $applicant->documents()
            ->where('read_status', ApplicantDocument::READ_DONE)
            ->orderBy('id')
            ->pluck('extracted_text')
            ->filter()
            ->implode("\n\n");

        Applicant::query()
            ->whereKey($applicant->id)
            ->toBase()
            ->update(['documents_text' => $text === '' ? null : mb_substr($text, 0, DocumentText::MAX_CHARS)]);
    }
}
