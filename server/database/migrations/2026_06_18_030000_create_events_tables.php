<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events & Meetings (ERD §9). Two tenant-scoped tables:
     *
     *  - **events** — a company event or meeting the organisation schedules: a
     *    title, an event/meeting kind, a date-time window, a location and an
     *    organiser. The lifecycle status (upcoming / ongoing / past) is **derived
     *    from the window**, not stored, so it can never drift.
     *  - **event_attendees** — the invitees for an event, each with a response
     *    (invited / accepted / declined / tentative) and the moment they were
     *    notified.
     *
     * Created in-module (there is no Company-Setup config for events, like
     * training). `ends_at` is nullable — a quick meeting may have only a start.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // event|meeting
            $table->string('type')->default('event');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            // Who is running it (an authenticated user).
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('starts_at');
            $table->index('type');
        });

        Schema::create('event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // invited|accepted|declined|tentative
            $table->string('response')->default('invited');
            // When the invite notification was delivered.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // One invitation per employee per event.
            $table->unique(['event_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendees');
        Schema::dropIfExists('events');
    }
};
