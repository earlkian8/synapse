<?php

use App\Support\Performance\PerformanceScorer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes performance scoring scale-agnostic and adds per-employee AI decision
 * support.
 *
 *  - **kpi_criteria** gain a configurable *rating scale*: `points` (an N-point
 *    numeric scale, e.g. 1–5 or 1–10), `percentage` (0–100), or a `scale` of
 *    named descriptive levels (each a label + value, covering letter grades,
 *    competency bands, pass/fail…). `scale_min` / `scale_max` bound it.
 *  - **performance_scores** snapshot the scale alongside the label + weight, so
 *    an evaluation's measurement method stays stable even if the criterion is
 *    later retuned or archived.
 *  - **performance_evaluations** gain `ai_insights` (the persisted LLM read).
 *
 * All existing rows default to the legacy 1–5 points scale, so historical
 * evaluations and the {@see PerformanceScorer} overall
 * (still normalised onto 1–5 for the ML pipeline) are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('scale_type')->default('points')->after('weight');
            $table->decimal('scale_min', 6, 2)->default(1)->after('scale_type');
            $table->decimal('scale_max', 6, 2)->default(5)->after('scale_min');
            $table->json('scale_levels')->nullable()->after('scale_max');
        });

        Schema::table('performance_scores', function (Blueprint $table) {
            $table->string('scale_type')->default('points')->after('weight');
            $table->decimal('scale_min', 6, 2)->default(1)->after('scale_type');
            $table->decimal('scale_max', 6, 2)->default(5)->after('scale_min');
            $table->json('scale_levels')->nullable()->after('scale_max');
        });

        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->json('ai_insights')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropColumn(['scale_type', 'scale_min', 'scale_max', 'scale_levels']);
        });

        Schema::table('performance_scores', function (Blueprint $table) {
            $table->dropColumn(['scale_type', 'scale_min', 'scale_max', 'scale_levels']);
        });

        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->dropColumn('ai_insights');
        });
    }
};
