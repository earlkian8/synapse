<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\RecruitmentPipelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A tenant-defined hiring process: a named, ordered set of stages a job posting
 * moves candidates through. Replaces the old system-wide hardcoded 6-stage list —
 * an organisation can make one pipeline and use it everywhere (today's behaviour,
 * unchanged) or make several for different kinds of hiring (see ADR 0029).
 */
class RecruitmentPipeline extends Model
{
    /** @use HasFactory<RecruitmentPipelineFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    protected $fillable = [
        'organization_id',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The pipeline's stages, in display/flow order.
     *
     * @return HasMany<RecruitmentPipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(RecruitmentPipelineStage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Keep at most one default pipeline per tenant — the one new postings
     * pre-select and the one existing data was backfilled onto.
     */
    public function enforceSingleDefault(): void
    {
        if (! $this->is_default) {
            return;
        }

        static::whereKeyNot($this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Reconcile the pipeline's stages with the editor's full ordered list —
     * update stages that carry an `id`, create the ones that don't, and drop any
     * existing stage the new list no longer names. Unlike a simple template
     * (e.g. onboarding's blueprint tasks), a stage a *live application* still
     * sits on can't just be deleted (its FK is `restrictOnDelete`), so a
     * dropped-but-still-referenced stage raises rather than silently failing at
     * the database.
     *
     * @param  array<int, array{id?: int, name: string, kind: string}>  $stages
     *
     * @throws RuntimeException when a removed stage still has applications on it.
     */
    public function syncStages(array $stages): void
    {
        $stages = array_values($stages);
        $keepIds = collect($stages)->pluck('id')->filter()->all();
        $removedIds = $this->stages()->whereNotIn('id', $keepIds ?: [0])->pluck('id');

        if ($removedIds->isNotEmpty()) {
            $inUse = JobApplication::whereIn('recruitment_pipeline_stage_id', $removedIds)->exists();

            if ($inUse) {
                throw new RuntimeException('One or more removed stages still have candidates on them — move those candidates first.');
            }

            $this->stages()->whereIn('id', $removedIds)->delete();
        }

        foreach ($stages as $index => $stage) {
            if (! empty($stage['id'])) {
                $this->stages()->whereKey($stage['id'])->update([
                    'name' => $stage['name'],
                    'kind' => $stage['kind'],
                    'position' => $index,
                ]);
            } else {
                $this->stages()->create([
                    'name' => $stage['name'],
                    'kind' => $stage['kind'],
                    'position' => $index,
                ]);
            }
        }

        $this->unsetRelation('stages');
    }

    /**
     * The stage a brand-new application starts at: the first open stage, in order.
     */
    public function entryStage(): ?RecruitmentPipelineStage
    {
        return $this->stages->firstWhere('kind', 'open');
    }

    /**
     * The single stage that means "hired." Pipelines are validated to have
     * exactly one, so this should never be null for a real pipeline.
     */
    public function wonStage(): ?RecruitmentPipelineStage
    {
        return $this->stages->firstWhere('kind', 'won');
    }

    /**
     * The stage rejecting a candidate moves them to by default — the first `lost`
     * stage in position order.
     */
    public function defaultLostStage(): ?RecruitmentPipelineStage
    {
        return $this->stages->firstWhere('kind', 'lost');
    }

    /**
     * The next `open` stage after the given one, or null when it's already the
     * last open stage (the next real move from there is Hire, not Advance).
     */
    public function nextOpenStageAfter(RecruitmentPipelineStage $stage): ?RecruitmentPipelineStage
    {
        return $this->stages
            ->where('kind', 'open')
            ->where('position', '>', $stage->position)
            ->sortBy('position')
            ->first();
    }

    /**
     * Whether the given stage is the last `open` stage in this pipeline — the
     * point where the only forward moves left are Hire or Reject.
     */
    public function isLastOpenStage(RecruitmentPipelineStage $stage): bool
    {
        return $stage->kind === 'open' && $this->nextOpenStageAfter($stage) === null;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across a pipeline's name.
     *
     * @param  Builder<RecruitmentPipeline>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where('name', $like, $needle);
    }
}
