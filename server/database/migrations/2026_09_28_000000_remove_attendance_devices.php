<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiosks and biometric scanners are gone (ADR 0054) — the reversal of the
     * device half of 2026_09_20_000000_create_work_locations_and_attendance_devices.
     * Work locations, the geofence and offline phone punches stay.
     *
     *  - `attendance_devices` is dropped, and `attendance_punches` loses the
     *    column that named one (with its unique index). `external_id` stays: a
     *    phone's queued punch is recognised by it.
     *  - `employees.device_enrollment_id` is dropped.
     *  - A punch a kiosk or scanner recorded keeps its `source` ("kiosk",
     *    "biometric"). It is what happened; nothing truer can be written there,
     *    and a day already judged by it stays judged.
     *  - An attendance policy stops listing the two sources. One that allowed
     *    only them allows every remaining way instead of none.
     *  - The wizard forgets its Devices step.
     *  - Activity entries about a device keep their words and lose the pointer
     *    to a row that no longer exists.
     *  - `setup.devices.manage` is pruned by the registry sync, and its role
     *    grants with it.
     */
    public function up(): void
    {
        DB::table('activity_logs')
            ->where('subject_type', 'App\\Models\\AttendanceDevice')
            ->update(['subject_type' => null, 'subject_id' => null]);

        DB::table('organizations')->whereNotNull('setup_steps')->orderBy('id')->each(function (object $organization): void {
            $steps = json_decode((string) $organization->setup_steps, true);

            if (is_array($steps) && array_key_exists('devices', $steps)) {
                unset($steps['devices']);
                DB::table('organizations')->where('id', $organization->id)->update(['setup_steps' => json_encode($steps)]);
            }
        });

        DB::table('attendance_policies')->orderBy('id')->each(function (object $policy): void {
            $settings = json_decode((string) $policy->settings, true);
            $sources = $settings['capture']['allowed_sources'] ?? null;

            if (! is_array($sources) || array_intersect($sources, ['kiosk', 'biometric']) === []) {
                return;
            }

            $kept = array_values(array_diff($sources, ['kiosk', 'biometric']));
            $settings['capture']['allowed_sources'] = $kept === [] ? ['web', 'mobile', 'manual'] : $kept;

            DB::table('attendance_policies')->where('id', $policy->id)->update(['settings' => json_encode($settings)]);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropUnique(['attendance_device_id', 'external_id']);
            $table->dropConstrainedForeignId('attendance_device_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'device_enrollment_id']);
            $table->dropColumn('device_enrollment_id');
        });

        Schema::dropIfExists('attendance_devices');

        PermissionSyncer::sync();
    }

    /**
     * The structure comes back empty: the devices, their keys and the
     * enrolment ids are not recoverable, and a policy's sources stay as they
     * were rewritten.
     */
    public function down(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('api_key_hash', 64)->unique();
            $table->string('api_key_hint', 8);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('csv_mapping')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->after('source')->constrained()->nullOnDelete();

            $table->unique(['attendance_device_id', 'external_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('device_enrollment_id', 64)->nullable()->after('employee_no');

            $table->unique(['organization_id', 'device_enrollment_id']);
        });
    }
};
