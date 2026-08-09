<?php

namespace App\Support;

use App\Models\Applicant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persists an applicant's supporting uploads (cover letter, certificates,
 * transcript, portfolio, government ID, …) to the public disk and records an
 * `applicant_documents` row for each.
 *
 * One place so every intake path — the recruiter's add-candidate form, the
 * applicant edit form, and the public careers application — stores files
 * identically. `uploadedBy` is null for public submissions.
 */
class ApplicantDocumentStore
{
    /**
     * Store the given document rows against the applicant.
     *
     * Each row is `['type' => string, 'file' => UploadedFile]`; rows without a
     * real upload are skipped. Returns the number stored.
     *
     * @param  array<int, array{type?: string, file?: mixed}>  $documents
     */
    public static function store(Applicant $applicant, array $documents, ?int $uploadedBy = null): int
    {
        $stored = 0;

        foreach ($documents as $document) {
            $file = $document['file'] ?? null;

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $applicant->documents()->create([
                'title' => $file->getClientOriginalName(),
                'type' => $document['type'] ?? 'other',
                'file' => $file->store('applicant-documents', 'public'),
                'uploaded_by' => $uploadedBy,
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * Delete the applicant's résumé from disk (and clear the column). Used when
     * a new résumé replaces it, and when the candidate is removed altogether.
     */
    public static function forgetResume(Applicant $applicant): void
    {
        if ($applicant->resume) {
            Storage::disk('public')->delete($applicant->resume);
            $applicant->resume = null;
        }
    }

    /**
     * Delete every file the applicant has on disk — the résumé and each
     * supporting document — so removing a candidate never orphans uploads,
     * whichever surface removed them.
     */
    public static function purge(Applicant $applicant): void
    {
        self::forgetResume($applicant);

        foreach ($applicant->documents as $document) {
            Storage::disk('public')->delete($document->file);
        }
    }
}
