<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance Forecast (Predictive Workforce Analytics). HR triggers a
     * **forecast run** that projects every active employee's next performance
     * rating through the Gradient-Boosting performance regressor (served by the
     * FastAPI inference service), producing a 0–100 predicted rating, a confidence
     * grounded in how much of the employee's own history fed the forecast, and a
     * Below / On track / Exceeds band.
     *
     *  - **performance_forecast_runs** — one batch forecast: when it ran, who
     *    triggered it, the model version it used, the evaluation period it targets,
     *    and a summary (counts per band, average rating + confidence). A failed run
     *    (service unreachable) is not persisted; only completed runs are kept.
     *  - **performance_forecasts** — the per-employee result of a run: the predicted
     *    rating, the confidence, the band, a snapshot of the features sent to the
     *    model (audit) and the employee's recent rating history (for the trajectory
     *    chart). One row per employee per run.
     *
     * Everything is tenant-scoped (organization_id). Mirrors the
     * promotion_readiness_runs / promotion_readiness_scores header-plus-lines shape
     * (ADR 0017) and, beneath that, performance_evaluations / performance_scores.
     */
    public function up(): void
    {
        Schema::create('performance_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            // The evaluation period being forecast (next non-closed cycle); null when
            // the tenant has no upcoming period configured.
            $table->foreignId('target_period_id')->nullable()->constrained('evaluation_periods')->nullOnDelete();

            // completed|failed (only completed runs are persisted today).
            $table->string('status')->default('completed');
            // e.g. "HistGradientBoostingRegressor@2026-06-23T11:49:11" from the service.
            $table->string('model_version')->nullable();

            $table->unsignedInteger('employees_scored')->default(0);
            // Band tallies: predicted rating >=80 | 60–79 | <60.
            $table->unsignedInteger('exceeds_count')->default(0);
            $table->unsignedInteger('on_track_count')->default(0);
            $table->unsignedInteger('below_count')->default(0);
            // Average predicted rating (0–100) and average confidence (0–1) across the
            // run; null when nothing scored.
            $table->decimal('average_rating', 5, 2)->nullable();
            $table->decimal('average_confidence', 4, 3)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('performance_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_forecast_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Model-predicted next-period rating (0–100) and the confidence in it
            // (0–1) = the share of the model's key inputs grounded in real HR data.
            $table->decimal('predicted_rating', 5, 2)->default(0);
            $table->decimal('confidence', 4, 3)->default(0);
            // below|on_track|exceeds
            $table->string('band')->default('on_track');
            // The feature vector sent to the model (audit) and the employee's recent
            // actual ratings (0–100, oldest→newest) that power the trajectory chart.
            $table->json('features')->nullable();
            $table->json('history')->nullable();

            $table->timestamps();

            // One forecast per employee per run.
            $table->unique(['performance_forecast_run_id', 'employee_id']);
            $table->index('band');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_forecasts');
        Schema::dropIfExists('performance_forecast_runs');
    }
};
