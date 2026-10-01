<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a day is judged against, frozen onto the day (ADR 0036).
     *
     *  - `scheduled_start_at` / `scheduled_end_at` — the instants the shift's
     *    clock-face times fell on for this work date, in the organisation's zone.
     *    A 22:00–06:00 shift ends on the following morning, which a `time` column
     *    combined with the work date cannot say. The `time` columns stay for
     *    display.
     *  - `rules` — the grace, required minutes, working-day verdict, schedule and
     *    holiday the day was judged by, so editing a schedule does not quietly
     *    re-judge every past day. Versioned JSON: later phases grow it here.
     *
     * All nullable. Rows written before this migration are given their snapshot by
     * `php artisan attendance:recompute`, or on the next recompute that touches them.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->timestamp('scheduled_start_at')->nullable()->after('scheduled_end');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->json('rules')->nullable()->after('scheduled_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['scheduled_start_at', 'scheduled_end_at', 'rules']);
        });
    }
};
