<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\RatingModel;
use Database\Factories\ReviewTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An **appraisal framework** (ADR 0028): how one population of a company is
 * reviewed. It carries weighted sections ("Goals 60%, Competencies 30%, Values
 * 10%"), the items inside them, an eligibility rule deciding who it applies to,
 * and the tenant's own **rating model** — the outcome bands the result is
 * reported in (see {@see RatingModel}).
 *
 * A framework is a live configuration object, so an evaluation snapshots the
 * parts of it that decide a result. Editing a framework changes the next
 * appraisal, never a past one.
 */
class ReviewTemplate extends Model
{
    /** @use HasFactory<ReviewTemplateFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    /** Who a framework applies to. */
    public const APPLIES_TO = ['all', 'department', 'position', 'employment_type'];

    /** How the overall result is led with on the scorecard. */
    public const RESULT_DISPLAYS = ['band', 'percent', 'points'];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'rating_scale_id',
        'sections',
        'bands',
        'result_display',
        'applies_to',
        'applies_to_values',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'bands' => 'array',
            'applies_to_values' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The framework's sections in reading order, each complete. A framework with
     * none behaves as a single unnamed section holding everything, which is what
     * a flat criteria list has always been.
     *
     * @return list<array{key: string, name: string, description: string|null, weight: float}>
     */
    public function sectionList(): array
    {
        $sections = [];

        foreach ($this->sections ?? [] as $section) {
            if (! is_array($section) || trim((string) ($section['name'] ?? '')) === '') {
                continue;
            }

            $name = trim((string) $section['name']);

            $sections[] = [
                'key' => (string) ($section['key'] ?? Str::slug($name, '_')),
                'name' => $name,
                'description' => isset($section['description']) && trim((string) $section['description']) !== ''
                    ? trim((string) $section['description'])
                    : null,
                'weight' => round((float) ($section['weight'] ?? 0), 2),
            ];
        }

        return $sections === [] ? [self::fallbackSection()] : $sections;
    }

    /**
     * The rating model, in reading order.
     *
     * @return list<array{key: string, label: string, min_percent: float, description: string|null, tone: string}>
     */
    public function bandList(): array
    {
        $bands = RatingModel::normalize($this->bands ?? []);

        return $bands === [] ? RatingModel::defaultBands() : $bands;
    }

    /**
     * The section every item lands in when a framework declares none.
     *
     * @return array{key: string, name: string, description: string|null, weight: float}
     */
    public static function fallbackSection(): array
    {
        return ['key' => 'overall', 'name' => 'Performance criteria', 'description' => null, 'weight' => 100.0];
    }

    /**
     * Whether this framework is the one to review the given employee with. `all`
     * matches everyone; the others match on the employee's own attributes.
     */
    public function coversEmployee(Employee $employee): bool
    {
        $values = $this->applies_to_values ?? [];

        return match ($this->applies_to) {
            'department' => in_array((string) $employee->department_id, array_map(strval(...), $values), true),
            'position' => in_array((string) $employee->position_id, array_map(strval(...), $values), true),
            'employment_type' => in_array((string) $employee->employment_type, array_map(strval(...), $values), true),
            default => true,
        };
    }

    /**
     * The scale an item of this framework falls back to.
     *
     * @return BelongsTo<RatingScale, $this>
     */
    public function ratingScale(): BelongsTo
    {
        return $this->belongsTo(RatingScale::class);
    }

    /**
     * @return HasMany<ReviewTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReviewTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PerformanceEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(PerformanceEvaluation::class);
    }

    /**
     * Limit to frameworks still offered for new evaluations.
     *
     * @param  Builder<ReviewTemplate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The tenant's default first, then active, then by name.
     *
     * @param  Builder<ReviewTemplate>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('name');
    }
}
