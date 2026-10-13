<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform admin portal (ADR 0085).
     *
     *  - `admins` — the people who run SYNAPSE itself. A separate identity from
     *    `users`: an admin belongs to no organisation, holds no ERP role, and an
     *    ERP account can never sign in to the portal (nor an admin to the ERP).
     *    Email is stored lower-cased, so uniqueness is case-insensitive.
     *  - `admin_activity_logs` — what admins did, append-only. The actor's name
     *    and email are copied onto each row, so the trail still reads after the
     *    admin is deleted (the foreign key is nulled, the words stay).
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            // super_admin|admin
            $table->string('role', 16)->default('admin');
            $table->boolean('is_active')->default(true);
            // Set when another admin issued the password: it must be replaced at
            // the next sign-in before anything else opens.
            $table->boolean('must_change_password')->default(true);
            // Encrypted TOTP secret; enrolment is confirmed by the first good code.
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('event', 40)->index();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
        Schema::dropIfExists('admins');
    }
};
