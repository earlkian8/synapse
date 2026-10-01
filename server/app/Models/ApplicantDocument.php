<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
     * The public URL of the stored file.
     */
    public function fileUrl(): ?string
    {
        return $this->file ? Storage::disk('public')->url($this->file) : null;
    }
}
