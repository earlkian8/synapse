<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0098 — the predictive screens say why.
 *
 * Every score keeps the explanation the inference service gave with it (the typical
 * record's score its reasons add up from; for promotion, what the next appraisal
 * would do; for a forecast, the chance of every band), and every run keeps what its
 * scores were read against (the typical record, where the tiers start, and for
 * attrition the leave rates of the data the model learned from). Stored with the run
 * rather than fetched when the page opens, so an old run is always explained by the
 * model that scored it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attrition_risk_runs', function (Blueprint $table) {
            $table->json('reference')->nullable()->after('average_confidence');
        });

        Schema::table('attrition_risk_scores', function (Blueprint $table) {
            $table->json('explanation')->nullable()->after('factors');
        });

        Schema::table('promotion_readiness_runs', function (Blueprint $table) {
            $table->json('reference')->nullable()->after('unassessed');
        });

        Schema::table('promotion_readiness_scores', function (Blueprint $table) {
            $table->json('explanation')->nullable()->after('factors');
        });

        Schema::table('performance_forecast_runs', function (Blueprint $table) {
            $table->json('reference')->nullable()->after('unassessed');
        });

        Schema::table('performance_forecasts', function (Blueprint $table) {
            $table->json('explanation')->nullable()->after('warnings');
        });
    }

    public function down(): void
    {
        Schema::table('performance_forecasts', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });

        Schema::table('performance_forecast_runs', function (Blueprint $table) {
            $table->dropColumn('reference');
        });

        Schema::table('promotion_readiness_scores', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });

        Schema::table('promotion_readiness_runs', function (Blueprint $table) {
            $table->dropColumn('reference');
        });

        Schema::table('attrition_risk_scores', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });

        Schema::table('attrition_risk_runs', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
