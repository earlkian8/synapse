<?php

use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appraisal frameworks (ADR 0028) — the rating model becomes the tenant's own.
 *
 * A performance programme is not "score everyone 1–5 against one global list of
 * criteria". Different companies measure different populations against different
 * things, on different scales, and report the result in their own words. Three
 * new tables carry that:
 *
 *  - **rating_scales** — a reusable measurement instrument: a numeric range, a
 *    percentage, or an ordered set of named levels with behavioural anchors
 *    (BARS). Defined once, referenced by many criteria.
 *  - **review_templates** — an appraisal framework: weighted *sections*, an
 *    eligibility rule that decides who it applies to, and — the important part —
 *    a **rating model**: the ordered outcome bands ("Outstanding", "Meets
 *    Expectations", "A", "3 of 4"…) the final result is reported in, and how it
 *    is displayed.
 *  - **review_template_items** — the weighted criteria of a framework, each in a
 *    section and on its own rating scale.
 *
 * Evaluations snapshot the framework they were opened from (name, sections,
 * bands, display) so retuning it later never rewrites a past appraisal, and gain
 * `overall_percent` — attainment on 0–100, which is the real canonical figure.
 * `overall_score` stays as the 1–5 projection the ML pipeline and analytics were
 * built on (see {@see PerformanceScorer}).
 *
 * The inline scale columns on `kpi_criteria` are folded into `rating_scales` so
 * there is one place a scale is defined; the snapshot columns on
 * `performance_scores` stay, because a snapshot is exactly what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createTables();
        $this->extendExistingTables();
        $this->backfill();

        // The scale now lives on rating_scales; a criterion points at one.
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropColumn(['scale_type', 'scale_min', 'scale_max', 'scale_levels']);
        });
    }

    public function down(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('scale_type')->default('points');
            $table->decimal('scale_min', 6, 2)->default(1);
            $table->decimal('scale_max', 6, 2)->default(5);
            $table->json('scale_levels')->nullable();
        });

        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_template_id');
            $table->dropColumn([
                'template_name', 'template_sections', 'template_bands',
                'result_display', 'overall_percent', 'result_band', 'result_label',
            ]);
        });

        Schema::table('performance_scores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_template_item_id');
            $table->dropColumn([
                'section_key', 'section_name', 'section_weight',
                'description', 'scale_name', 'sort_order',
            ]);
        });

        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rating_scale_id');
            $table->dropColumn('sort_order');
        });

        Schema::dropIfExists('review_template_items');
        Schema::dropIfExists('review_templates');
        Schema::dropIfExists('rating_scales');
    }

    /**
     * The three new configuration tables.
     */
    private function createTables(): void
    {
        Schema::create('rating_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // numeric|percentage|levels
            $table->string('type')->default('numeric');
            $table->decimal('min', 8, 2)->default(1);
            $table->decimal('max', 8, 2)->default(5);
            // The granularity of a numeric scale (1 = whole points, 0.5 = halves).
            $table->decimal('step', 6, 2)->default(1);
            // Ordered levels for a descriptive scale: [{value, label, description}].
            $table->json('levels')->nullable();
            // The scale offered first when building a framework.
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('review_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // The scale an item falls back to when it names none of its own.
            $table->foreignId('rating_scale_id')->nullable()->constrained()->nullOnDelete();

            // Weighted sections: [{key, name, description, weight}].
            $table->json('sections')->nullable();
            // The rating model — ordered outcome bands:
            // [{key, label, min_percent, description, tone}].
            $table->json('bands')->nullable();
            // How the overall is reported: band|percent|points.
            $table->string('result_display')->default('band');

            // Eligibility: all|department|position|employment_type (+ the values).
            $table->string('applies_to')->default('all');
            $table->json('applies_to_values')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('review_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_template_id')->constrained()->cascadeOnDelete();
            // The catalogue criterion this item draws from (nullable: an item may
            // be written straight into the framework).
            $table->foreignId('kpi_criterion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rating_scale_id')->nullable()->constrained()->nullOnDelete();

            $table->string('section_key')->default('overall');
            $table->string('name');
            $table->text('description')->nullable();
            // The item's weight *within its section*.
            $table->decimal('weight', 6, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['review_template_id', 'sort_order']);
        });
    }

    /**
     * Point criteria at a rating scale, give score lines their section + item
     * provenance, and give evaluations the framework snapshot and the 0–100
     * attainment the band is resolved from.
     */
    private function extendExistingTables(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->foreignId('rating_scale_id')->nullable()->after('weight')->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        Schema::table('performance_scores', function (Blueprint $table) {
            $table->foreignId('review_template_item_id')->nullable()->after('kpi_criterion_id')->constrained()->nullOnDelete();
            // Section snapshot — a line carries its section so the scorecard can be
            // rebuilt (and re-weighted) without the framework.
            $table->string('section_key')->default('overall')->after('label');
            $table->string('section_name')->nullable()->after('section_key');
            $table->decimal('section_weight', 6, 2)->default(0)->after('section_name');
            $table->text('description')->nullable()->after('section_weight');
            $table->string('scale_name')->nullable()->after('scale_type');
            $table->unsignedInteger('sort_order')->default(0)->after('remarks');
        });

        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->foreignId('review_template_id')->nullable()->after('evaluation_period_id')->constrained()->nullOnDelete();
            $table->string('template_name')->nullable()->after('review_template_id');
            $table->json('template_sections')->nullable()->after('template_name');
            $table->json('template_bands')->nullable()->after('template_sections');
            $table->string('result_display')->default('band')->after('template_bands');

            // Attainment on 0–100 — the canonical figure the band is read from.
            $table->decimal('overall_percent', 5, 2)->nullable()->after('overall_score');
            $table->string('result_band')->nullable()->after('overall_percent');
            $table->string('result_label')->nullable()->after('result_band');
        });
    }

    /**
     * Fold every tenant's existing programme into the new shape: the scales its
     * criteria already used become rating_scales rows, its criteria become one
     * default framework, and its evaluations gain the attainment + band they
     * always implied.
     */
    private function backfill(): void
    {
        $now = now();
        $bands = json_encode(RatingModel::defaultBands());

        $organizations = DB::table('kpi_criteria')->distinct()->pluck('organization_id');

        foreach ($organizations as $organizationId) {
            $criteria = DB::table('kpi_criteria')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->get();

            // One rating_scales row per distinct scale the tenant already used.
            $scaleIds = [];

            foreach ($criteria as $criterion) {
                $key = "{$criterion->scale_type}:{$criterion->scale_min}:{$criterion->scale_max}:{$criterion->scale_levels}";

                if (! isset($scaleIds[$key])) {
                    $scaleIds[$key] = DB::table('rating_scales')->insertGetId([
                        'organization_id' => $organizationId,
                        'name' => $this->scaleName($criterion, count($scaleIds)),
                        'description' => 'Carried over from the criteria this scale was configured on.',
                        'type' => $criterion->scale_type === 'points' ? 'numeric' : ($criterion->scale_type === 'scale' ? 'levels' : 'percentage'),
                        'min' => $criterion->scale_min,
                        'max' => $criterion->scale_max,
                        'step' => 1,
                        'levels' => $criterion->scale_levels,
                        'is_default' => count($scaleIds) === 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('kpi_criteria')
                    ->where('id', $criterion->id)
                    ->update(['rating_scale_id' => $scaleIds[$key]]);
            }

            $defaultScaleId = $scaleIds === [] ? null : reset($scaleIds);

            $templateId = DB::table('review_templates')->insertGetId([
                'organization_id' => $organizationId,
                'name' => 'Standard Appraisal',
                'description' => 'The criteria set this organisation was already evaluating against.',
                'rating_scale_id' => $defaultScaleId,
                'sections' => json_encode([[
                    'key' => 'overall',
                    'name' => 'Performance criteria',
                    'description' => null,
                    'weight' => 100,
                ]]),
                'bands' => $bands,
                'result_display' => 'band',
                'applies_to' => 'all',
                'applies_to_values' => null,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $live = $criteria
                ->filter(fn (object $criterion): bool => $criterion->deleted_at === null && (bool) $criterion->is_active)
                ->values();

            foreach ($live as $index => $criterion) {
                DB::table('review_template_items')->insert([
                    'organization_id' => $organizationId,
                    'review_template_id' => $templateId,
                    'kpi_criterion_id' => $criterion->id,
                    'rating_scale_id' => $criterion->rating_scale_id ?? $defaultScaleId,
                    'section_key' => 'overall',
                    'name' => $criterion->name,
                    'description' => $criterion->description,
                    'weight' => $criterion->weight,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->backfillEvaluations($organizationId, $templateId, $bands, RatingModel::defaultBands());
        }
    }

    /**
     * Stamp every existing evaluation with the framework snapshot, its 0–100
     * attainment (read back off the 1–5 overall it already carried) and the band
     * that attainment falls in.
     *
     * @param  list<array<string, mixed>>  $bandList
     */
    private function backfillEvaluations(int $organizationId, int $templateId, string $bands, array $bandList): void
    {
        DB::table('performance_scores')
            ->whereIn('performance_evaluation_id', function ($query) use ($organizationId) {
                $query->select('id')->from('performance_evaluations')->where('organization_id', $organizationId);
            })
            ->update(['section_key' => 'overall', 'section_name' => 'Performance criteria', 'section_weight' => 100]);

        $evaluations = DB::table('performance_evaluations')
            ->where('organization_id', $organizationId)
            ->get(['id', 'overall_score']);

        foreach ($evaluations as $evaluation) {
            // The old overall lived on 1–5; the same result on 0–100 is that
            // position on the scale, which is what the band is read from.
            $percent = $evaluation->overall_score === null
                ? null
                : round((((float) $evaluation->overall_score) - 1) / 4 * 100, 2);

            $band = $percent === null ? null : RatingModel::bandFor($percent, $bandList);

            DB::table('performance_evaluations')->where('id', $evaluation->id)->update([
                'review_template_id' => $templateId,
                'template_name' => 'Standard Appraisal',
                'template_sections' => json_encode([[
                    'key' => 'overall',
                    'name' => 'Performance criteria',
                    'description' => null,
                    'weight' => 100,
                ]]),
                'template_bands' => $bands,
                'result_display' => 'band',
                'overall_percent' => $percent,
                'result_band' => $band['key'] ?? null,
                'result_label' => $band['label'] ?? null,
            ]);
        }
    }

    /**
     * A readable name for a scale recovered from a criterion's inline columns.
     */
    private function scaleName(object $criterion, int $index): string
    {
        return match ($criterion->scale_type) {
            'percentage' => 'Percentage (0–100%)',
            'scale' => $index === 0 ? 'Descriptive levels' : "Descriptive levels {$index}",
            default => rtrim(rtrim((string) $criterion->scale_min, '0'), '.').'–'.rtrim(rtrim((string) $criterion->scale_max, '0'), '.').' points',
        };
    }
};
