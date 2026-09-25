<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0045 — Promotion Readiness and Performance Forecast say what they rest on.
 *
 * Runs record who could not be assessed and why (no completed appraisal, one still
 * in progress, none before the forecast period), instead of scoring them from a
 * guess. A readiness score records the history it rests on; a forecast records the
 * range the next rating is expected in. Both keep any note about an input the model
 * had to adjust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_readiness_runs', function (Blueprint $table) {
            // [{employee_id, reason}] — employees the model declined, and why.
            $table->json('unassessed')->nullable()->after('average_score');
        });

        Schema::table('promotion_readiness_scores', function (Blueprint $table) {
            // latest_appraisal | two_appraisals
            $table->string('basis')->nullable()->after('tier');
            // The completed appraisals the score rests on (oldest first), and notes
            // about any input held at the model's trained range.
            $table->json('history')->nullable()->after('features');
            $table->json('warnings')->nullable()->after('history');
        });

        Schema::table('performance_forecast_runs', function (Blueprint $table) {
            $table->json('unassessed')->nullable()->after('average_confidence');
        });

        Schema::table('performance_forecasts', function (Blueprint $table) {
            // The range four in five next ratings land in (0–100).
            $table->decimal('predicted_low', 5, 2)->nullable()->after('predicted_rating');
            $table->decimal('predicted_high', 5, 2)->nullable()->after('predicted_low');
            $table->json('warnings')->nullable()->after('history');
        });
    }

    public function down(): void
    {
        Schema::table('performance_forecasts', function (Blueprint $table) {
            $table->dropColumn(['predicted_low', 'predicted_high', 'warnings']);
        });

        Schema::table('performance_forecast_runs', function (Blueprint $table) {
            $table->dropColumn('unassessed');
        });

        Schema::table('promotion_readiness_scores', function (Blueprint $table) {
            $table->dropColumn(['basis', 'history', 'warnings']);
        });

        Schema::table('promotion_readiness_runs', function (Blueprint $table) {
            $table->dropColumn('unassessed');
        });
    }
};
