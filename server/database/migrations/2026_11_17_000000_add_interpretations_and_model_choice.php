<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The tables holding one person's result per predictive run. */
    private const SCORES = ['attrition_risk_scores', 'promotion_readiness_scores', 'performance_forecasts'];

    /**
     * Two changes to the predictive surfaces (ADRs 0106, 0107):
     *
     *  - **interpretation** on each score table and on `local_models` — the plain-
     *    language reading an LLM wrote of one result (why the system scored a person
     *    as it did; what a model check means for the organisation), saved so a second
     *    look costs no model call. It names the context blocks it read, so it is
     *    shown only to viewers allowed to see every one of them.
     *  - **passed** on `local_models` — whether the attempt passed its check, kept
     *    apart from `status`. A model that did not pass is now stored too, and the
     *    people who manage the surface may switch to it knowingly; `status` stays the
     *    lifecycle (`failed` now means only that no model could be stored).
     *
     * Rows from before are backfilled: every stored model had passed, every failed
     * attempt had not.
     */
    public function up(): void
    {
        Schema::table('local_models', function (Blueprint $table) {
            $table->boolean('passed')->nullable()->after('status');
            $table->json('interpretation')->nullable()->after('findings');
        });

        DB::table('local_models')->update(['passed' => DB::raw("status <> 'failed'")]);

        foreach (self::SCORES as $scores) {
            Schema::table($scores, function (Blueprint $table) {
                $table->json('interpretation')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::SCORES as $scores) {
            Schema::table($scores, function (Blueprint $table) {
                $table->dropColumn('interpretation');
            });
        }

        Schema::table('local_models', function (Blueprint $table) {
            $table->dropColumn(['passed', 'interpretation']);
        });
    }
};
