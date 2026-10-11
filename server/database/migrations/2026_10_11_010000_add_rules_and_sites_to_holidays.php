<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holidays that move, and holidays kept in one place only (ADR 0078).
     *
     *  - `holidays.recurrence` — how a holiday repeats: `none` (its date only),
     *    `yearly` (the same month and day), `easter` (a number of days from
     *    Easter Sunday — Holy Week) or `weekday` (the n-th weekday of a month —
     *    National Heroes Day). `is_recurring` stays, true for any repeating rule.
     *  - `holidays.rule` — the rule's numbers: `{offset}` for Easter,
     *    `{month, weekday, week}` (ISO weekday, week −1 = the last) otherwise.
     *  - `holiday_work_location` — the sites a local holiday is kept at. A
     *    holiday with none is kept everywhere.
     *
     * Every yearly holiday so far was a fixed date, so it becomes `yearly`.
     */
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('recurrence', 16)->default('none')->after('is_recurring');
            $table->json('rule')->nullable()->after('recurrence');
        });

        DB::table('holidays')->where('is_recurring', true)->update(['recurrence' => 'yearly']);

        Schema::create('holiday_work_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['holiday_id', 'work_location_id']);
            $table->index('work_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_work_location');

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'rule']);
        });
    }
};
