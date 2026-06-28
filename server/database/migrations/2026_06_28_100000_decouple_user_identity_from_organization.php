<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decouple identity from tenant (ADR 0023, supersedes the "one user ⇒ one
     * organisation" point of ADR 0005).
     *
     * A `User` is now a global identity (login/email/password) that can belong to
     * many organisations through the new `organization_user` membership pivot. The
     * row-level `organization_id` tenancy on every business table is unchanged — we
     * only stop deriving the active tenant from a single column on `users` and start
     * deriving it from the request's session (web) or token (mobile), validated
     * against membership.
     */
    public function up(): void
    {
        // Identity is no longer pinned to one tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        // Membership: which organisations an identity may act in. `is_default` is the
        // org a fresh login lands in.
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // One identity can now be an employee in several organisations (one per org).
        // `user_id` stays nullable (employees may have no login yet); Postgres treats
        // NULLs as distinct, so multiple no-login employees per org remain allowed.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->unique(['organization_id', 'user_id']);
        });

        // Each mobile token is bound to exactly one active organisation (set at mint,
        // validated against membership on every request).
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('tokenable_type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'user_id']);
            $table->unique(['user_id']);
        });

        Schema::dropIfExists('organization_user');

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });
    }
};
