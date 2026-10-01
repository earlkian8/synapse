<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 of the attendance plan: punches that can be trusted, and days that
     * close themselves (ADR 0040, ADR 0041).
     *
     *  - `work_locations` — a company's sites, each a fence (a point and a radius)
     *    that may also name the schedule and attendance policy people based there
     *    default to. `employee_work_locations` says who is based where, one site
     *    marked primary.
     *  - `attendance_devices` — kiosks and biometric scanners. A device is
     *    authenticated by a key, of which only a hash is kept.
     *  - `attendance_punches` gains what capture learned: the nearest site, the
     *    distance to it and whether the punch was on it; the device that sent it
     *    and the device's own id for it (so a resend is recognised); and, for a
     *    punch queued offline, the time the phone gave it, when the server got it,
     *    and how far the phone's clock was off.
     *  - `attendance_records.closed_at` — when the end-of-day job closed the day.
     *  - `employees.device_enrollment_id` — the id a scanner knows somebody by,
     *    when it is not their employee number.
     *  - `organizations.attendance_closed_from` / `attendance_closed_through` —
     *    the first and last dates the end-of-day job has closed: it resumes after
     *    the last, and a change of plan reaches back no further than the first.
     *
     * Nothing already recorded is re-judged.
     */
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(150);
            // Links in the shift and policy precedence chains (between the
            // department and the organisation).
            $table->foreignId('default_work_schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();
            $table->foreignId('attendance_policy_id')->nullable()->constrained('attendance_policies')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('employee_work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'work_location_id']);
            $table->index('work_location_id');
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // kiosk|biometric
            $table->string('type');
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            // SHA-256 of the key; the key itself is shown once and never stored.
            $table->string('api_key_hash', 64)->unique();
            // The key's last four characters, so a person can tell keys apart.
            $table->string('api_key_hint', 8);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            // How this device's CSV export maps onto a punch, remembered once.
            $table->json('csv_mapping')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('accuracy')->constrained()->nullOnDelete();
            $table->unsignedInteger('distance_meters')->nullable()->after('work_location_id');
            // null: not checked (no site to check against, or a source that
            // punches at a known place).
            $table->boolean('within_geofence')->nullable()->after('distance_meters');
            $table->foreignId('attendance_device_id')->nullable()->after('source')->constrained()->nullOnDelete();
            // The sender's own id for the punch — a device's, or a phone's for a
            // queued punch — so sending it again is recognised.
            $table->string('external_id', 100)->nullable()->after('attendance_device_id');
            $table->timestamp('device_punched_at')->nullable()->after('punched_at');
            $table->timestamp('received_at')->nullable()->after('device_punched_at');
            // How far the sender's clock was from the server's when it sent the
            // punch (sender minus server).
            $table->integer('clock_skew_seconds')->nullable()->after('received_at');

            $table->unique(['attendance_device_id', 'external_id']);
            $table->index(['employee_id', 'external_id']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('signed_off_overtime_minutes');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('device_enrollment_id', 64)->nullable()->after('employee_no');

            $table->unique(['organization_id', 'device_enrollment_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->date('attendance_closed_from')->nullable()->after('attendance_lock_reminder_days');
            $table->date('attendance_closed_through')->nullable()->after('attendance_closed_from');
        });

        // setup.locations.view / .manage and setup.devices.manage. The HR Manager
        // holds every permission already; nobody else is granted them by default.
        PermissionSyncer::sync();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['attendance_closed_from', 'attendance_closed_through']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'device_enrollment_id']);
            $table->dropColumn('device_enrollment_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropUnique(['attendance_device_id', 'external_id']);
            $table->dropIndex(['employee_id', 'external_id']);
            $table->dropConstrainedForeignId('attendance_device_id');
            $table->dropConstrainedForeignId('work_location_id');
            $table->dropColumn(['distance_meters', 'within_geofence', 'external_id', 'device_punched_at', 'received_at', 'clock_skew_seconds']);
        });

        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('employee_work_locations');
        Schema::dropIfExists('work_locations');
    }
};
