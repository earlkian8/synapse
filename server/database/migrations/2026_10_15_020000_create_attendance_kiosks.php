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
     * The ways to punch before kiosks came back. A policy that allowed all of
     * them allowed every way there was.
     *
     * @var list<string>
     */
    private const EVERY_WAY_BEFORE = ['web', 'mobile', 'manual'];

    /**
     * Kiosks, for the people who have no phone to clock in with (ADR 0092).
     *
     *  - `attendance_kiosks`: a shared device at a site. It is paired with a
     *    one-time code and then known by a device secret kept in its cookie;
     *    only the SHA-256 of either is stored.
     *  - `employee_kiosk_pins`: the PIN a person clocks in with at a kiosk, as a
     *    password hash, with its wrong-try count and lock.
     *  - `attendance_punches.attendance_kiosk_id`: the kiosk a punch was made at.
     *  - A policy that allowed every way to punch allows the kiosk too. One that
     *    chose some ways keeps its choice.
     *  - `attendance.kiosks.manage` is new and goes to every company's HR Manager.
     */
    public function up(): void
    {
        Schema::create('attendance_kiosks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_token_hash', 64)->nullable()->unique();
            $table->string('device_label', 120)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'deleted_at']);
        });

        Schema::create('employee_kiosk_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pin_hash');
            $table->boolean('must_change')->default(true);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_kiosk_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });

        DB::table('attendance_policies')->orderBy('id')->each(function (object $policy): void {
            $settings = json_decode((string) $policy->settings, true);
            $sources = $settings['capture']['allowed_sources'] ?? null;

            if (! is_array($sources) || array_diff(self::EVERY_WAY_BEFORE, $sources) !== [] || in_array('kiosk', $sources, true)) {
                return;
            }

            $settings['capture']['allowed_sources'] = [...$sources, 'kiosk'];

            DB::table('attendance_policies')->where('id', $policy->id)->update(['settings' => json_encode($settings)]);
        });

        PermissionSyncer::grant(['attendance.kiosks.manage'], [Role::HR_MANAGER]);
    }

    public function down(): void
    {
        DB::table('attendance_policies')->orderBy('id')->each(function (object $policy): void {
            $settings = json_decode((string) $policy->settings, true);
            $sources = $settings['capture']['allowed_sources'] ?? null;

            if (! is_array($sources) || ! in_array('kiosk', $sources, true)) {
                return;
            }

            $kept = array_values(array_diff($sources, ['kiosk']));
            $settings['capture']['allowed_sources'] = $kept === [] ? self::EVERY_WAY_BEFORE : $kept;

            DB::table('attendance_policies')->where('id', $policy->id)->update(['settings' => json_encode($settings)]);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_kiosk_id');
        });

        Schema::dropIfExists('employee_kiosk_pins');
        Schema::dropIfExists('attendance_kiosks');
    }
};
