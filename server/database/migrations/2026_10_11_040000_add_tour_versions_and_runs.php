<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tour that changes, and what people do in it (ADR 0080).
     *
     *  - `users.tour_version` — which version of the web tour the person last
     *    answered. Lower than the current one: the stops added since are offered
     *    as a short "What's new". Everybody who answered the first tour answered
     *    version 1.
     *  - `users.mobile_tour_version` / `mobile_tour_finished_at` — the same for the
     *    mobile app's own tour, which nobody has seen yet.
     *  - `product_tour_runs` — every run of either tour: which stops it showed,
     *    how far it got, how it ended (or that it never did), for the per-stop
     *    numbers on System → Product tour.
     *
     * `product-tour.view` reads those numbers; the HR Manager gets it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('tour_version')->nullable()->after('tour_outcome');
            $table->unsignedSmallInteger('mobile_tour_version')->nullable()->after('tour_version');
            $table->timestamp('mobile_tour_finished_at')->nullable()->after('mobile_tour_version');
        });

        // Query-builder, not Eloquent: tour state is not an edit to anybody's
        // account, so it must not move `updated_at`.
        DB::table('users')->whereNotNull('tour_finished_at')->update(['tour_version' => 1]);

        Schema::create('product_tour_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // web|mobile
            $table->string('platform', 8);
            $table->unsignedSmallInteger('version');
            // first|whats_new|replay
            $table->string('mode', 12);
            // The stops this run showed, in order.
            $table->json('steps');
            // The furthest step reached (its index in `steps`), and its id.
            $table->unsignedSmallInteger('furthest_index')->default(0);
            $table->string('last_step', 40)->nullable();
            // completed|skipped; null while running — or abandoned.
            $table->string('outcome', 12)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'platform', 'version']);
            $table->index(['user_id', 'started_at']);
        });

        PermissionSyncer::grant(['product-tour.view'], [Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tour_runs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tour_version', 'mobile_tour_version', 'mobile_tour_finished_at']);
        });
    }
};
