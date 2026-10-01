<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Model graduation, made real (ADR 0046, superseding the frontend-only panel of
     * ADR 0031). Once an organisation has recorded enough of its own history, HR can
     * have the inference service fit a surface's model on those records and check it
     * against the model in use today; if it proves more accurate on the
     * organisation's own people, HR can switch the surface to it.
     *
     *  - **local_models** — one training attempt per row, for one surface
     *    (`promotion` | `performance` | `attrition`): the verdict, the comparison it
     *    was judged on, the plain-language findings shown to HR, the volumes it was
     *    trained on, and — when it passed — the version the service stored it under.
     *    Status runs `failed` (nothing stored) | `ready` (passed, not in use) →
     *    `active` (scoring this surface; at most one per surface) → `retired`.
     *  - **local_model_id** on each surface's runs — which of the organisation's own
     *    models scored the run, or null for the general model, so a historical run
     *    always says whose model spoke.
     *
     * Tenant-scoped (organization_id), like every analytics table.
     */
    public function up(): void
    {
        Schema::create('local_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // promotion|performance|attrition — the surface it would score.
            $table->string('model', 32);
            // failed|ready|active|retired.
            $table->string('status', 16);
            // The inference service's version for a stored model; null when it failed.
            $table->string('version', 64)->nullable();

            // Examples it was trained and checked on, and the volumes behind them.
            $table->unsignedInteger('examples')->default(0);
            $table->json('counts')->nullable();
            // The measure it was judged on — the local model, the general one and
            // knowing nothing — and how often it came out ahead across resamples.
            $table->json('comparison')->nullable();
            // One plain-language sentence per check, written for the HR reader.
            $table->json('findings')->nullable();

            $table->foreignId('trained_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'model', 'status']);
        });

        foreach (['promotion_readiness_runs', 'performance_forecast_runs', 'attrition_risk_runs'] as $runs) {
            Schema::table($runs, function (Blueprint $table) {
                $table->foreignId('local_model_id')->nullable()->after('model_version')
                    ->constrained('local_models')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['promotion_readiness_runs', 'performance_forecast_runs', 'attrition_risk_runs'] as $runs) {
            Schema::table($runs, function (Blueprint $table) {
                $table->dropConstrainedForeignId('local_model_id');
            });
        }

        Schema::dropIfExists('local_models');
    }
};
