<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance Management (ERD §8), plus the two Company-Setup config tables it
     * reads from (ERD §2: kpi_criteria, evaluation_periods). The operational
     * tables:
     *
     *  - **kpi_criteria** — the weighted criteria an evaluation scores against
     *    (Quality of Work, Productivity…). Each carries a relative weight and an
     *    active flag; archivable (soft delete) so retiring one keeps historical
     *    evaluations intact.
     *  - **evaluation_periods** — a review cycle (start/end) with a status
     *    lifecycle (draft → open → closed). Evaluations are conducted within one.
     *  - **performance_evaluations** — one employee's appraisal for a period: the
     *    evaluator, the derived overall score, a status lifecycle (draft →
     *    submitted → acknowledged), and remarks. One per employee per period.
     *  - **performance_scores** — the per-criterion breakdown a score is built
     *    from. Each line snapshots the criterion's label + weight so an archived
     *    criterion never corrupts a submitted
     *    evaluation; the overall score is derived from these (see
     *    App\Support\Performance\PerformanceScorer).
     *
     * Everything is tenant-scoped (organization_id).
     */
    public function up(): void
    {
        // ── Company Setup config (referenced by evaluations) ────────────────────
        Schema::create('kpi_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Relative weight (percentage points); the overall score is weighted by it.
            $table->decimal('weight', 6, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('evaluation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            // draft|open|closed
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('start_date');
        });

        // ── Appraisals ──────────────────────────────────────────────────────────
        Schema::create('performance_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();

            // Weighted overall (1–5 scale), derived from the score lines; null until scored.
            $table->decimal('overall_score', 5, 2)->nullable();
            // draft|submitted|acknowledged
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            // One evaluation per employee per period.
            $table->unique(['employee_id', 'evaluation_period_id']);
            $table->index('status');
        });

        Schema::create('performance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_criterion_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of the criterion at scoring time, so an archived/removed
            // criterion still renders and the weighting stays stable.
            $table->string('label');
            $table->decimal('weight', 6, 2)->default(0);
            // Rating on a 1–5 scale; null until rated.
            $table->decimal('score', 5, 2)->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index('kpi_criterion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_scores');
        Schema::dropIfExists('performance_evaluations');
        Schema::dropIfExists('evaluation_periods');
        Schema::dropIfExists('kpi_criteria');
    }
};
