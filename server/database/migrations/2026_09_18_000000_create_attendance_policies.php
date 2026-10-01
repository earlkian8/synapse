<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 of the attendance plan (ADR 0038): a company decides *how a day is
     * judged* — grace, rounding, lateness thresholds, overtime, breaks, night
     * differential — by choosing a preset and adjusting typed options, and each
     * day comes out as minute buckets a payroll export can read.
     *
     *  - `attendance_policies` — a named, versioned settings document, one default
     *    per organisation, archived rather than deleted.
     *  - The policy attaches where a shift does: a dated assignment (one person
     *    judged differently), a schedule, a department.
     *  - `attendance_records` gains the buckets and the flags. `regular + overtime
     *    = worked`; night, rest-day and holiday minutes are tags over the same
     *    minutes, not more of them. `excused_late_minutes` is the lateness grace
     *    forgave, which a monthly grace allowance counts down from.
     *
     * Nothing already recorded is re-judged. The two buckets that follow directly
     * from what a record already says are back-filled here — regular is worked
     * less overtime, and overtime was never subject to approval, so all of it is
     * approved. Night, rest-day and holiday minutes need the punches and the
     * snapshot, so `php artisan attendance:recompute` fills them; with no policy
     * configured it reaches the same status and totals every day had.
     */
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Which preset it was made from, for "reset to preset". Null when the
            // company started from scratch, or the preset has since been retired.
            $table->string('preset_key')->nullable();
            // Grouped typed options, read by AttendancePolicySettings with a
            // default for every key it lacks.
            $table->json('settings')->nullable();
            $table->unsignedSmallInteger('settings_version')->default(1);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_default']);
        });

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->foreignId('attendance_policy_id')->nullable()->after('weekly_required_minutes')
                ->constrained('attendance_policies')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('attendance_policy_id')->nullable()->after('default_work_schedule_id')
                ->constrained('attendance_policies')->nullOnDelete();
        });

        Schema::table('employee_schedule_assignments', function (Blueprint $table) {
            $table->foreignId('attendance_policy_id')->nullable()->after('cycle_offset')
                ->constrained('attendance_policies')->nullOnDelete();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('regular_minutes')->default(0)->after('undertime_minutes');
            $table->unsignedInteger('approved_overtime_minutes')->default(0)->after('overtime_minutes');
            $table->unsignedInteger('night_minutes')->default(0)->after('approved_overtime_minutes');
            $table->unsignedInteger('rest_day_minutes')->default(0)->after('night_minutes');
            $table->unsignedInteger('holiday_minutes')->default(0)->after('rest_day_minutes');
            $table->unsignedInteger('excused_late_minutes')->default(0)->after('late_minutes');
            // ["late","half_day","unapproved_overtime",…] — everything more
            // specific than the status.
            $table->json('flags')->nullable()->after('status');
        });

        DB::table('attendance_records')->update([
            'regular_minutes' => DB::raw('CASE WHEN worked_minutes > overtime_minutes THEN worked_minutes - overtime_minutes ELSE 0 END'),
            'approved_overtime_minutes' => DB::raw('overtime_minutes'),
        ]);

        // The two new abilities (setup.attendance_policy.view / .manage).
        PermissionSyncer::sync();
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'regular_minutes', 'approved_overtime_minutes', 'night_minutes',
                'rest_day_minutes', 'holiday_minutes', 'excused_late_minutes', 'flags',
            ]);
        });

        Schema::table('employee_schedule_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_policy_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_policy_id');
        });

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_policy_id');
        });

        Schema::dropIfExists('attendance_policies');
    }
};
