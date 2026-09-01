<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RecruitmentPipelineStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a {@see RecruitmentPipeline}. Business logic (open/terminal checks,
 * hiring, rejecting, "what's next") keys off `kind` and `position` — never
 * `name` — so a stage can be called anything and still behave correctly. Exactly
 * one `won` stage and at least one `lost` stage per pipeline (enforced in
 * `StoreRecruitmentPipelineRequest`, not a DB constraint — this codebase validates
 * enum-shaped invariants at the app layer).
 */
class RecruitmentPipelineStage extends Model
{
    /** @use HasFactory<RecruitmentPipelineStageFactory> */
    use BelongsToOrganization, HasFactory;

    /** @var list<string> */
    public const KINDS = ['open', 'won', 'lost'];

    protected $fillable = [
        'organization_id',
        'recruitment_pipeline_id',
        'name',
        'kind',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentPipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipeline::class, 'recruitment_pipeline_id');
    }

    public function isOpen(): bool
    {
        return $this->kind === 'open';
    }

    public function isTerminal(): bool
    {
        return $this->kind !== 'open';
    }
}
