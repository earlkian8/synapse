<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attrition Risk (Predictive Workforce Analytics), restored as a real surface
     * (ADR 0043, superseding the frontend-only demo of ADR 0030). HR triggers an
     * **assessment run** that scores every active employee through the attrition
     * model — trained on the merged attrition surveys and served by the FastAPI
     * inference service — producing a 0–100 flight-risk score, a Stable / At watch /
     * High risk tier, and a confidence grounded in how much of the employee's own
     * record fed the score.
     *
     *  - **attrition_risk_runs** — one batch assessment: when it ran, who triggered
     *    it, the model version it used, and a summary (counts per tier, the average
     *    risk and the average confidence). Only completed runs are persisted.
     *  - **attrition_risk_scores** — the per-employee result of a run: the raw
     *    probability, the 0–100 score, the tier, the confidence, the explanation
     *    factors (when the model is linear), and a snapshot of the features sent to
     *    the model (audit). One row per employee per run.
     *
     * Everything is tenant-scoped (organization_id). Mirrors the
     * promotion_readiness_* / performance_forecast_* header-plus-lines shape.
     */
    public function up(): void
    {
        Schema::create('attrition_risk_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            // completed|failed (only completed runs are persisted today).
            $table->string('status')->default('completed');
            // e.g. "LogisticRegression@2026-09-24T10:00:00" from the inference service.
            $table->string('model_version')->nullable();

            $table->unsignedInteger('employees_scored')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            // Average risk (0–100) and average confidence (0–1) across the run; null
            // when nothing scored.
            $table->decimal('average_score', 5, 2)->nullable();
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
            // low|medium|high — Stable / At watch / High risk.
            $table->string('tier')->default('low');
            // The share of the model's inputs grounded in the employee's own record (0–1).
            $table->decimal('confidence', 4, 3)->default(0);
            // Top contributing factors [{feature,label,impact,direction}] (null for a
            // tree model) and the feature vector sent to the model, for audit.
            $table->json('factors')->nullable();
            $table->json('features')->nullable();

            $table->timestamps();

            // One score per employee per run.
            $table->unique(['attrition_risk_run_id', 'employee_id']);
            $table->index('tier');
        });

        // `analytics.attrition.view` / `.manage` are new; publish them, then grant
        // them the way OrganizationProvisioner does for a new tenant — both to every
        // HR Manager, view to every Department Head.
        PermissionSyncer::sync();

        $grants = [
            'hr-manager' => ['analytics.attrition.view', 'analytics.attrition.manage'],
            'department-head' => ['analytics.attrition.view'],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            $permissionIds = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');

            foreach (DB::table('roles')->where('name', $roleName)->pluck('id') as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $exists = DB::table('permission_role')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permissionId)
                        ->exists();

                    if (! $exists) {
                        DB::table('permission_role')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attrition_risk_scores');
        Schema::dropIfExists('attrition_risk_runs');

        // The permission rows (and their role grants, by cascade) go with the
        // registry entries on the next sync.
    }
};
