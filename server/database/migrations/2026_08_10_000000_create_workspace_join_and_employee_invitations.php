<?php

use App\Models\Organization;
use App\Support\JoinCode;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-served identity and the two ways into a workspace (ADR 0026).
     *
     * The ERP owns *employment* (the `employees` roster line); the person owns
     * *identity* (a `User` they register themselves). Nothing joins the two at hire
     * time any more — one of these two records does:
     *
     * - `employee_invitations` — HR points at a roster line and issues a claim
     *   ticket (a hashed link token plus a retypeable code).
     * - `organization_join_requests` — the person walks up with the organisation's
     *   `join_code` (Google Classroom's class code). They are admitted immediately
     *   when their registered email matches an unclaimed roster line, and otherwise
     *   wait here for HR to approve and pick the line.
     */
    public function up(): void
    {
        // The organisation-wide code people type to ask to join, and the switch that
        // turns it off. Existing tenants are back-filled with a code below.
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('join_code', 16)->nullable()->unique()->after('slug');
            $table->boolean('join_code_enabled')->default(true)->after('join_code');
        });

        // A claim ticket for one roster line. Two forms of one secret: `token` is the
        // sha256 of the emailed link (never stored in the clear), `code` is the short
        // string a person can retype into the app. `code` is unique GLOBALLY, not per
        // tenant, because it is redeemed before any tenant is bound.
        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('code', 16)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id']);
            $table->index('email');
        });

        // Someone who typed the join code but could not be matched to a roster line
        // automatically. One row per person per organisation; re-asking after a
        // decline revives the same row rather than piling up duplicates.
        Schema::create('organization_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Filled when HR approves and nominates the roster line to bind.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('decline_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
        });

        // Back-fill a join code for every organisation that predates this migration.
        Organization::withTrashed()->whereNull('join_code')->get()->each(
            fn (Organization $organization) => $organization
                ->forceFill(['join_code' => JoinCode::uniqueFor(Organization::class, 'join_code', JoinCode::ORGANIZATION_LENGTH)])
                ->save(),
        );

        // `employees.invite` is new; publish it and grant it to every owner role that
        // already holds the rest of the catalogue.
        PermissionSyncer::sync();

        $inviteId = DB::table('permissions')->where('name', 'employees.invite')->value('id');

        if ($inviteId !== null) {
            $ownerRoleIds = DB::table('roles')->where('name', 'hr-manager')->pluck('id');

            foreach ($ownerRoleIds as $roleId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $inviteId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $inviteId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_join_requests');
        Schema::dropIfExists('employee_invitations');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn(['join_code', 'join_code_enabled']);
        });
    }
};
