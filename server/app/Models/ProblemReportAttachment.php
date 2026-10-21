<?php

namespace App\Models;

use App\Support\Files\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screenshot on a problem report (ADR 0096), on the private uploads disk.
 * The reporter opens it through the ordinary file route ({@see Uploads}); an
 * admin through the portal's own, which reads the admin's token.
 */
class ProblemReportAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'problem_report_id',
        'path',
        'name',
        'mime',
        'size',
        'width',
        'height',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProblemReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ProblemReport::class, 'problem_report_id');
    }
}
