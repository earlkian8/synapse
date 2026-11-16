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
     * The ways to punch before the QR code came. A policy that allowed all of
     * them allowed every way there was.
     *
     * @var list<string>
     */
    private const EVERY_WAY_BEFORE = ['web', 'mobile', 'kiosk', 'manual'];

    /**
     * QR stations (ADR 0103): a screen at a site that shows a code which changes
     * every few seconds, and that people scan with their own phone to clock in.
     *
     *  - `attendance_qr_stations`: the station, the site it stands at, how often
     *    its code changes, the secret its codes are signed with (encrypted;
     *    never sent to any screen), whether it takes scans from any phone's
     *    camera or only the app's (`scan_mode`), and whether a scan must come
     *    through the same internet connection as its screen (`same_network`,
     *    checked against `last_shown_ip`).
     *  - `attendance_qr_scans`: every scan of a station's code by somebody of the
     *    company, accepted or refused, with why, and whether it came from the
     *    app or a phone's browser (`client`). What HR reads to see somebody
     *    trying a forwarded code or a faked location.
     *  - `attendance_punches`: the station a punch was scanned at, and the
     *    SHA-256 of the phone's install id.
     *  - A policy that allowed every way to punch allows the QR code too. One
     *    that chose some ways keeps its choice.
     *  - `attendance.qr.manage` and `attendance.qr.display` are new and go to
     *    every company's HR Manager.
     */
    public function up(): void
    {
        Schema::create('attendance_qr_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rotate_seconds')->default(15);
            $table->string('scan_mode', 10)->default('app');
            $table->boolean('same_network')->default(false);
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_shown_at')->nullable();
            $table->foreignId('last_shown_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_shown_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'deleted_at']);
        });

        Schema::create('attendance_qr_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_qr_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client', 10)->default('app');
            $table->string('outcome', 12);
            $table->string('reason', 32)->nullable();
            $table->string('punch_type', 20)->nullable();
            $table->foreignId('attendance_punch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->boolean('mocked')->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['attendance_qr_station_id', 'created_at']);
            $table->index(['device_hash', 'created_at']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_qr_station_id')->nullable()->after('attendance_kiosk_id')->constrained()->nullOnDelete();
            $table->string('device_hash', 64)->nullable()->after('attendance_qr_station_id');
        });

        DB::table('attendance_policies')->orderBy('id')->each(function (object $policy): void {
            $settings = json_decode((string) $policy->settings, true);
            $sources = $settings['capture']['allowed_sources'] ?? null;

            if (! is_array($sources) || array_diff(self::EVERY_WAY_BEFORE, $sources) !== [] || in_array('qr', $sources, true)) {
                return;
            }

            $settings['capture']['allowed_sources'] = [...$sources, 'qr'];

            DB::table('attendance_policies')->where('id', $policy->id)->update(['settings' => json_encode($settings)]);
        });

        PermissionSyncer::grant(['attendance.qr.manage', 'attendance.qr.display'], [Role::HR_MANAGER]);
    }

    public function down(): void
    {
        DB::table('attendance_policies')->orderBy('id')->each(function (object $policy): void {
            $settings = json_decode((string) $policy->settings, true);
            $sources = $settings['capture']['allowed_sources'] ?? null;

            if (! is_array($sources) || ! in_array('qr', $sources, true)) {
                return;
            }

            $kept = array_values(array_diff($sources, ['qr']));
            $settings['capture']['allowed_sources'] = $kept === [] ? self::EVERY_WAY_BEFORE : $kept;

            DB::table('attendance_policies')->where('id', $policy->id)->update(['settings' => json_encode($settings)]);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_qr_station_id');
            $table->dropColumn('device_hash');
        });

        Schema::dropIfExists('attendance_qr_scans');
        Schema::dropIfExists('attendance_qr_stations');
    }
};
