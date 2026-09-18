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
     * Phase 3 of the attendance plan (ADR 0039): employees ask for what the clock
     * could not capture, managers decide, and a closed period is frozen.
     *
     *  - `attendance_requests` — a correction, overtime, a day (or days) on
     *    official business or working remotely. One table for the four types; the
     *    type-specific ask is a JSON `payload`, validated per type. Single-day
     *    types keep `start_date = end_date`.
     *  - `attendance_periods` — the pay periods attendance closes on. A locked
     *    period cannot change through any path; the file written when it locked is
     *    what payroll received.
     *  - `attendance_punches` are soft-deleted from now on, and remember the
     *    correction that wrote or replaced them, so a fixed day keeps its trail.
     *  - `attendance_records.signed_off_overtime_minutes` — the overtime HR
     *    approved on the day itself. The evaluator reads it with approved overtime
     *    requests, so a sign-off survives every recompute.
     *  - `organizations` gains the period calendar and when to remind HR to lock.
     *
     * Nothing already recorded is re-judged here. `approval_status` is derived by
     * the evaluator from now on; `attendance:recompute` brings the stored values in
     * line.
     */
    public function up(): void
    {
        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // correction|overtime|official_business|remote_work
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            // The day it concerns, when one exists (a correction's, once approved).
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('reason');
            $table->string('attachment')->nullable();
            // pending|approved|rejected|cancelled
            $table->string('status')->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // Who filed it — HR can file on somebody's behalf.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'start_date']);
            $table->index(['organization_id', 'status']);
            $table->index('type');
        });

        Schema::create('attendance_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // open|locked
            $table->string('status')->default('open');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            // Why it was locked with the checklist still open, when it was.
            $table->text('lock_note')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('unlock_reason')->nullable();
            // The period summary written when it locked (the `local` disk).
            $table->string('export_path')->nullable();
            // When holders of attendance.period.manage were told it is due.
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'start_date']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            // The correction that wrote this punch, and the one that replaced it.
            $table->foreignId('attendance_request_id')->nullable()->after('recorded_by')
                ->constrained('attendance_requests')->nullOnDelete();
            $table->foreignId('replaced_by_request_id')->nullable()->after('attendance_request_id')
                ->constrained('attendance_requests')->nullOnDelete();
            $table->softDeletes();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('signed_off_overtime_minutes')->nullable()->after('approved_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            // weekly|bi_weekly|semi_monthly|monthly
            $table->string('attendance_period_frequency')->default('semi_monthly')->after('timezone');
            $table->unsignedSmallInteger('attendance_lock_reminder_days')->default(2)->after('attendance_period_frequency');
        });

        // The four new abilities, and the built-in roles that should hold them in
        // every existing company. HR Manager holds everything already.
        PermissionSyncer::sync();

        $grant = function (string $role, array $permissions): void {
            $roleIds = DB::table('roles')->where('name', $role)->pluck('id');
            $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        };

        $grant(Role::STAFF, ['attendance.request']);
        $grant(Role::DEPARTMENT_HEAD, ['attendance.request', 'attendance.requests.review']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['attendance_period_frequency', 'attendance_lock_reminder_days']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('signed_off_overtime_minutes');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_request_id');
            $table->dropConstrainedForeignId('attendance_request_id');
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('attendance_periods');
        Schema::dropIfExists('attendance_requests');
    }
};
