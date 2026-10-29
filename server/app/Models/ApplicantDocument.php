<?php

namespace App\Models;

use App\Jobs\ReadApplicantFiles;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Files\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting file attached to an applicant (cover letter, certificate,
 * transcript, portfolio, government ID, …). Mirrors {@see EmployeeDocument};
 * the applicant's primary CV stays on `applicants.resume`.
 */
class ApplicantDocument extends Model
{
    use BelongsToOrganization;

    /** Waiting to be read (ADR 0097). */
    public const READ_PENDING = 'pending';

    /** Read, and its text kept. */
    public const READ_DONE = 'read';

    /** Holds no text that could be read. */
    public const READ_UNREADABLE = 'unreadable';

    /** Deliberately never read. */
    public const READ_SKIPPED = 'skipped';

    /** Types never read: a government ID is kept as the picture it is. */
    public const UNREAD_TYPES = ['government_id'];

    protected $fillable = [
        'organization_id',
        'applicant_id',
        'title',
        'type',
        'file',
        'uploaded_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['extracted_text'];

    /**
     * A new document is read once the response has gone, unless it is a
     * government ID (ADR 0097).
     */
    protected static function booted(): void
    {
        static::creating(function (ApplicantDocument $document): void {
            $document->read_status = in_array($document->type, self::UNREAD_TYPES, true)
                ? self::READ_SKIPPED
                : self::READ_PENDING;
        });

        static::created(function (ApplicantDocument $document): void {
            if ($document->read_status === self::READ_PENDING) {
                ReadApplicantFiles::dispatchAfterResponse($document->organization_id, $document->applicant_id);
            }
        });
    }

    /**
     * @return BelongsTo<Applicant, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A link to the stored file, through the app (ADR 0087).
     */
    public function fileUrl(): ?string
    {
        return $this->file ? Uploads::url($this->file) : null;
    }
}
