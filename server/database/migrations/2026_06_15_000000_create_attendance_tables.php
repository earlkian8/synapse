<?php

use App\Support\Attendance\AttendanceCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance — the Daily Time Record (DTR), the operational half of HR that
     * sits beside Leave. Two tables:
     *
     *  - **attendance_records**: one row per employee per day — the computed
     *    summary (status + totals) with a snapshot of the work schedule that
     *    applied, plus an optional correction/approval lifecycle. Totals are
     *    derived from punches by {@see AttendanceCalculator},
     *    never trusted from the client.
     *  - **attendance_punches**: the raw punch events (in / out / break) that the
     *    summary is built from. Each carries its capture context — source
     *    (web/mobile/kiosk/biometric/manual), GPS coordinates and an optional
     *    selfie — so a mobile DTR app's punches are fully auditable.
     *
     * Both tables are tenant-scoped (`organization_id`). See ADR for the punch
     * model + mobile API.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            // Snapshot of the schedule that applied this day (the assignment can
            // change later, but the record keeps the times it was judged against).
            $table->foreignId('work_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->time('scheduled_start')->nullable();
            $table->time('scheduled_end')->nullable();

            // present|late|undertime|absent|on_leave|day_off|holiday|incomplete
            $table->string('status')->default('absent');

            // Derived from the punches.
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            $table->boolean('is_manual')->default(false); // entered / edited by HR
            $table->text('remarks')->nullable();

            // Correction / overtime approval lifecycle (null = nothing to review).
            $table->string('approval_status')->nullable(); // pending|approved|rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // One DTR row per employee per day.
            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
            $table->index('status');
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            // Denormalised so an employee's punch history queries without a join.
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type'); // clock_in|clock_out|break_start|break_end
            $table->timestamp('punched_at');
            $table->string('source')->default('web'); // web|mobile|kiosk|biometric|manual

            // Capture context (where/who) — all optional.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable(); // metres
            $table->string('photo')->nullable();           // selfie path (public disk)
            $table->string('note')->nullable();
            // Who recorded it; null when the employee punched for themselves.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'punched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_records');
    }
};
