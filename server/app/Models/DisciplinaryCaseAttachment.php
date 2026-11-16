<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Files\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of a disciplinary case's evidence (ADR 0105) — a photo, a scan of a
 * written report, a CCTV still. Private to whoever may see the case: the file
 * is on the workspace-only uploads disk (ADR 0087), under `discipline/`.
 */
class DisciplinaryCaseAttachment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'disciplinary_case_id',
        'path',
        'name',
        'size',
        'uploaded_by',
    ];

    /**
     * @return BelongsTo<DisciplinaryCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'disciplinary_case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        return Uploads::url($this->path);
    }
}
