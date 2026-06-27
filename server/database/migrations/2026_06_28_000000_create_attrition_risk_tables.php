<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attrition Risk (Predictive Workforce Analytics). HR triggers an
     * **assessment run** that scores every active employee through the
     * Random-Forest attrition model (served by the FastAPI inference service),
     * producing a 0–100 risk score, a Low/Medium/High tier and a confidence.
     *
     *  - **attrition_risk_runs** — one batch assessment: when it ran, who
     *    triggered it, the model version it used, and a summary (counts per tier +
     *    the average risk and average confidence). A failed run (service
     *    unreachable) is not persisted; only completed runs are kept.
     *  - **attrition_risk_scores** — the per-employee result of a run: the raw
     *    probability, the 0–100 score, the tier, a confidence (the share of the
     *    model's key inputs grounded in real HR data vs imputed), and a snapshot of
     *    the features sent to the model (audit). One row per employee per run.
     *
     * Unlike Promotion Readiness (a linear model that exposes per-employee factor
     * contributions), the attrition model is a Random Forest — it returns no
     * per-instance factors, so this mirrors Performance Forecast: a derived
     * confidence plus the grounded feature snapshot stand in for the "why".
     *
     * Everything is tenant-scoped (organization_id). Mirrors the
     * promotion_readiness / performance_forecast header-plus-lines shape.
     */
    public function up(): void
    {
        Schema::create('attrition_risk_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            // completed|failed (only completed runs are persisted today).
            $table->string('status')->default('completed');
            // e.g. "RandomForestClassifier@2026-06-28T01:07:48" from the inference service.
            $table->string('model_version')->nullable();

            $table->unsignedInteger('employees_scored')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            // Average risk across the run (0–100); null when nothing scored.
            $table->decimal('average_score', 5, 2)->nullable();
            // Average confidence (0–1): mean share of grounded inputs across the cohort.
            $table->decimal('average_confidence', 4, 3)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('attrition_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attrition_risk_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Raw model probability of leaving (0–1) and its 0–100 presentation score.
            $table->decimal('probability', 6, 5)->default(0);
            $table->decimal('score', 5, 2)->default(0);
            // low|medium|high
            $table->string('tier')->default('low');
            // Share of the model's key inputs grounded in this employee's real HR data.
            $table->decimal('confidence', 4, 3)->default(0);
            // The feature vector sent to the model, for transparency/audit.
            $table->json('features')->nullable();

            $table->timestamps();

            // One score per employee per run.
            $table->unique(['attrition_risk_run_id', 'employee_id']);
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attrition_risk_scores');
        Schema::dropIfExists('attrition_risk_runs');
    }
};
