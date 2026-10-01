<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The clock an organisation keeps (ADR 0036).
     *
     * Storage stays UTC. What moves is *judgement* — "today", "late", and which
     * day a punch belongs to are questions asked of the office wall clock, and a
     * company in Manila and one in New York read different walls at the same
     * instant. Until now every one of them was answered in UTC.
     *
     * An IANA identifier (`Asia/Manila`), never an offset: an offset cannot know
     * about daylight saving. Existing organisations are back-filled with Manila —
     * the zone every tenant so far has actually been running in — by the column
     * default; a company in another zone corrects it on its Company Profile.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Manila')->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
