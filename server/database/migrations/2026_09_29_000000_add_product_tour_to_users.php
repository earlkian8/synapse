<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a person has been shown around the app (ADR 0060).
     *
     * Everybody arriving in the app shell for the first time — the owner who just
     * finished company setup, an employee who just joined — is offered a short
     * tour of what their role can reach. These two columns are how it is offered
     * once rather than on every sign-in:
     *
     *  - `tour_finished_at` — set the first time the person finishes the tour or
     *    skips it. Null means "offer it on the next page of the app".
     *  - `tour_outcome` — `completed` or `skipped`, the answer they gave then.
     *
     * The tour belongs to the person, not to a workspace: it describes the app,
     * which is the same app in every company they belong to.
     *
     * Every account that predates the tour has been using the app already, so it
     * is back-filled as finished — with no outcome, because it was never offered.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_finished_at')->nullable()->after('password_changed_at');
            $table->string('tour_outcome', 16)->nullable()->after('tour_finished_at');
        });

        // Query-builder, not Eloquent: back-filling tour state is not an edit to
        // anybody's account, so it must not move `updated_at`.
        DB::table('users')->update(['tour_finished_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tour_finished_at', 'tour_outcome']);
        });
    }
};
