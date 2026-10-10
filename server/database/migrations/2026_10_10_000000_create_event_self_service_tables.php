<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events for everybody invited to them (ADR 0070): answering one's own
     * invitations, repeating events, rooms, a calendar subscription, and
     * reminders that go out on their own.
     *
     *  - **rooms** — the meeting rooms and other spaces an event can hold, with
     *    where they are and how many they seat. Retired, or archived, a room
     *    keeps the bookings it already has.
     *  - **event_series** — the rule a repeating event follows (daily, weekly on
     *    chosen days, or monthly, every n, until a date or for a count). Each
     *    occurrence is an ordinary `events` row pointing at it, so answers,
     *    reminders, rooms and the feed work per occurrence.
     *  - **calendar_feeds** — one secret subscription link per person per
     *    company: the sha-256 of the token to look it up by, and the token
     *    itself encrypted, so the link can be shown again.
     *  - `events`: the series, the room, how long before the start a reminder
     *    goes out (null = none), and when it went.
     *  - `event_attendees.responded_at`: when the invitee last answered.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Building, floor, or a video link — free text.
            $table->string('location', 160)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
        });

        Schema::create('event_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // daily|weekly|monthly
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('interval')->default(1);
            // ISO weekdays (1 = Monday … 7 = Sunday), for a weekly rule.
            $table->json('weekdays')->nullable();
            $table->date('until')->nullable();
            $table->unsignedSmallInteger('count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('organizer_id')->constrained('event_series')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->after('location')->constrained('rooms')->nullOnDelete();
            // Minutes before the start; null sends no automatic reminder.
            $table->unsignedSmallInteger('reminder_minutes')->nullable()->after('room_id');
            $table->timestamp('reminder_sent_at')->nullable()->after('reminder_minutes');

            $table->index(['room_id', 'starts_at']);
            $table->index(['series_id', 'starts_at']);
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->timestamp('responded_at')->nullable()->after('response');
        });

        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // Encrypted with the app key (the model's `encrypted` cast).
            $table->text('token');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // Every employee answers their own invitations: the built-in roles of
        // every company get it, as OrganizationProvisioner gives a new one.
        PermissionSyncer::grant(['events.respond'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropColumn('responded_at');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'starts_at']);
            $table->dropIndex(['series_id', 'starts_at']);
            $table->dropConstrainedForeignId('series_id');
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['reminder_minutes', 'reminder_sent_at']);
        });

        Schema::dropIfExists('event_series');
        Schema::dropIfExists('rooms');

        // The permission rows (and their role grants, by cascade) go with the
        // registry entries on the next sync.
    }
};
