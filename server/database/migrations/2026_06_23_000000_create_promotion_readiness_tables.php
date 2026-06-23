<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotion Readiness (Predictive Workforce Analytics). HR triggers an
     * **assessment run** that scores every active employee through the
     * Logistic-Regression promotion model (served by the FastAPI inference
     * service), producing a 0–100 readiness score, a Low/Medium/High tier and the
     * contributing factors per employee.
     *
     *  - **promotion_readiness_runs** — one batch assessment: when it ran, who
     *    triggered it, the model version it used, and a summary (counts per tier +
     *    the average readiness). A failed run (service unreachable) is not
     *    persisted; only completed runs are kept.
     *  - **promotion_readiness_scores** — the per-employee result of a run: the raw
     *    probability, the 0–100 score, the tier, the explanation factors, and a
     *    snapshot of the features sent to the model (audit). One row per employee
     *    per run.
     *
     * Everything is tenant-scoped (organization_id). Mirrors the
     * performance_evaluations / performance_scores header-plus-lines shape.
     */
    public function up(): void
    {
        Schema::create('promotion_readiness_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            // completed|failed (only completed runs are persisted today).
            $table->string('status')->default('completed');
            // e.g. "LogisticRegression@2026-06-23T03:19:36" from the inference service.
            $table->string('model_version')->nullable();

            $table->unsignedInteger('employees_scored')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            // Average readiness across the run (0–100); null when nothing scored.
            $table->decimal('average_score', 5, 2)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('promotion_readiness_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_readiness_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Raw model probability (0–1) and its 0–100 presentation score.
            $table->decimal('probability', 6, 5)->default(0);
            $table->decimal('score', 5, 2)->default(0);
            // low|medium|high
            $table->string('tier')->default('low');
            // Top contributing factors [{feature,label,impact,direction}] and the
            // feature vector sent to the model, both for transparency/audit.
            $table->json('factors')->nullable();
            $table->json('features')->nullable();

            $table->timestamps();

            // One score per employee per run.
            $table->unique(['promotion_readiness_run_id', 'employee_id']);
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_readiness_scores');
        Schema::dropIfExists('promotion_readiness_runs');
    }
};
