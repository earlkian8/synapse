<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fences that are not circles, and sites on their own clock (ADR 0079).
     *
     *  - `fence_type` — `circle` (a point and a radius, as before) or `polygon`.
     *  - `polygon` — the shape's corners, `[[lat, lng], …]`, 3 to 50 of them. For a
     *    shape, `latitude`/`longitude` are its centre and `radius_meters` the
     *    circle that holds it, so the map and "nearest site" keep working.
     *  - `timezone` — the IANA zone the site keeps. Null: the organisation's. The
     *    people based there are judged on it (ADR 0036 named the organisation's
     *    clock for every day; a site may now have its own).
     */
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            $table->string('fence_type', 16)->default('circle')->after('radius_meters');
            $table->json('polygon')->nullable()->after('fence_type');
            $table->string('timezone', 64)->nullable()->after('polygon');
        });
    }

    public function down(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            $table->dropColumn(['fence_type', 'polygon', 'timezone']);
        });
    }
};
