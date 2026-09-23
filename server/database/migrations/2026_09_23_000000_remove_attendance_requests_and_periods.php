<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance requests and periods are gone (the reversal of
     * 2026_09_19_000000_create_attendance_requests_and_periods), and the shift
     * roster moves to Company Setup.
     *
     *  - The roster's two abilities are renamed `attendance.roster.*` →
     *    `setup.roster.*` in place, so every role that held them still does.
     *  - A punch a correction wrote becomes what it now is — entered by HR on the
     *    employee's behalf (`manual`). Punches a correction replaced stay
     *    soft-deleted, as an edit's do.
     *  - `attendance_punches` loses the request columns; its soft deletes stay
     *    (HR's edit keeps what the day said before).
     *  - `attendance_records.signed_off_overtime_minutes` stays: signing a day off
     *    is the board's, not a request's.
     *  - `organizations` loses the period calendar.
     *  - Activity entries about a request or a period keep their words and lose
     *    the pointer to a row that no longer exists.
     *  - The request, period, and unlock abilities are pruned by the registry sync,
     *    and their role grants with them.
     *
     * Days a locked period froze are ordinary days from here on. Run
     * `php artisan attendance:recompute` afterwards: a day of official business
     * or remote work is judged by its punches again.
     */
    public function up(): void
    {
        $this->rename('attendance.roster.view', 'setup.roster.view');
        $this->rename('attendance.roster.manage', 'setup.roster.manage');

        DB::table('attendance_punches')->where('source', 'correction')->update(['source' => 'manual']);

        DB::table('activity_logs')
            ->whereIn('subject_type', ['App\\Models\\AttendanceRequest', 'App\\Models\\AttendancePeriod'])
            ->update(['subject_type' => null, 'subject_id' => null]);

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_request_id');
            $table->dropConstrainedForeignId('attendance_request_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['attendance_period_frequency', 'attendance_lock_reminder_days']);
        });

        Schema::dropIfExists('attendance_periods');
        Schema::dropIfExists('attendance_requests');

        PermissionSyncer::sync();
    }

    /**
     * The structure comes back empty: what was filed, decided and locked is not
     * recoverable. The roster's abilities return to their old names.
     */
    public function down(): void
    {
        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('reason');
            $table->string('attachment')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
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
            $table->string('status')->default('open');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('lock_note')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('unlock_reason')->nullable();
            $table->string('export_path')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'start_date']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_request_id')->nullable()->after('recorded_by')
                ->constrained('attendance_requests')->nullOnDelete();
            $table->foreignId('replaced_by_request_id')->nullable()->after('attendance_request_id')
                ->constrained('attendance_requests')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('attendance_period_frequency')->default('semi_monthly')->after('timezone');
            $table->unsignedSmallInteger('attendance_lock_reminder_days')->default(2)->after('attendance_period_frequency');
        });

        $this->rename('setup.roster.view', 'attendance.roster.view');
        $this->rename('setup.roster.manage', 'attendance.roster.manage');
    }

    /**
     * Rename a permission, keeping every role that held it. When the new name
     * already exists (a registry sync got there first), the grants move onto it
     * and the old row goes.
     */
    private function rename(string $from, string $to): void
    {
        $old = DB::table('permissions')->where('name', $from)->value('id');

        if ($old === null) {
            return;
        }

        $new = DB::table('permissions')->where('name', $to)->value('id');

        if ($new === null) {
            DB::table('permissions')->where('id', $old)->update(['name' => $to]);

            return;
        }

        foreach (DB::table('permission_role')->where('permission_id', $old)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $new]);
        }

        DB::table('permissions')->where('id', $old)->delete();
    }
};
