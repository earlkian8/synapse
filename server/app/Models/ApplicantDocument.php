<?php

namespace App\Models;

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

    protected $fillable = [
        'organization_id',
        'applicant_id',
        'title',
        'type',
        'file',
        'uploaded_by',
    ];

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
