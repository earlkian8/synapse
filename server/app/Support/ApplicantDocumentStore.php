<?php

namespace App\Support;

use App\Http\Requests\Recruitment\StoreApplicantRequest;
use App\Models\Applicant;
use App\Services\Assistant\Attachments\StoredAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persists an applicant's supporting uploads (cover letter, certificates,
 * transcript, portfolio, government ID, …) to the public disk and records an
 * `applicant_documents` row for each.
 *
 * One place so every intake path — the recruiter's add-candidate form, the
 * applicant edit form, the public careers application, and a file sent to the
 * assistant in chat (ADR 0068) — stores files identically. `uploadedBy` is null
 * for public submissions.
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
     * Why a chat attachment cannot be filed on an applicant — the form's own
     * type and size rules, in its own terms — or null when it can.
     */
    public static function problemWith(StoredAttachment $file): ?string
    {
        $allowed = explode(',', StoreApplicantRequest::FILE_MIMES);

        if (! in_array($file->extension(), $allowed, true)) {
            return "“{$file->name}” can't be filed on a candidate: it must be one of ".implode(', ', $allowed).'.';
        }

        if ($file->size > StoreApplicantRequest::FILE_MAX_KB * 1024) {
            return "“{$file->name}” is larger than ".(StoreApplicantRequest::FILE_MAX_KB / 1024).' MB.';
        }

        return null;
    }

    /**
     * Make a chat attachment the applicant's résumé, replacing any earlier one.
     * The applicant is saved.
     */
    public static function resumeFrom(Applicant $applicant, StoredAttachment $file): void
    {
        self::forgetResume($applicant);
        $applicant->resume = $file->copyTo('public', 'applicant-resumes');
        $applicant->save();
    }

    /**
     * File a chat attachment as one of the applicant's supporting documents.
     */
    public static function documentFrom(Applicant $applicant, StoredAttachment $file, string $type, ?int $uploadedBy = null): void
    {
        $applicant->documents()->create([
            'title' => $file->name,
            'type' => in_array($type, StoreApplicantRequest::DOCUMENT_TYPES, true) ? $type : 'other',
            'file' => $file->copyTo('public', 'applicant-documents'),
            'uploaded_by' => $uploadedBy,
        ]);
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
