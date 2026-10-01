<?php

use App\Support\Attendance\DayRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 of the attendance plan (ADR 0037): a schedule stops being one pair
     * of times and becomes a **template** with a day pattern; who works it is a
     * **dated assignment**; and one date can be overridden on a **roster**.
     *
     *  - `work_schedules` gains a `type` (fixed / flexible / hours_only) and a
     *    cycle, so a rotation ("4 on, 4 off") is expressible alongside a week.
     *  - `work_schedule_days` holds one row per day of that cycle: rest day or
     *    not, one or more segments (a split shift is two), the day's required
     *    minutes, and the flexible windows.
     *  - `employee_schedule_assignments` gives a schedule a date range, so moving
     *    somebody to another shift next month leaves last month alone.
     *  - `shift_roster_entries` is the one-off: a swap, a Saturday call-in, a day
     *    off — this person, this date, these hours.
     *  - Departments and organisations get a default schedule, so a new hire is
     *    covered before anyone assigns them anything.
     *
     * The backfill keeps every existing tenant's numbers identical: each schedule's
     * flat `work_days` + `start_time`/`end_time` becomes seven day rows, and each
     * employee's `work_schedule_id` becomes one open-ended assignment. The legacy
     * columns stay (read-only, maintained by the editor) for one release — the
     * employee screens, the mobile session and the assistant still read them.
     */
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            // fixed | flexible | hours_only
            $table->string('type')->default('fixed')->after('name');
            // 7 for a weekly pattern; N for a rotation (8 = four on, four off).
            $table->unsignedSmallInteger('cycle_length_days')->default(7)->after('type');
            // Which date day 1 of the cycle falls on. Rotations only; a weekly
            // pattern indexes off the weekday and ignores it.
            $table->date('cycle_anchor_date')->nullable()->after('cycle_length_days');
            // An hours_only schedule may target a week rather than a day.
            $table->unsignedInteger('weekly_required_minutes')->nullable()->after('required_hours');
        });

        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            // 1..cycle_length_days. For a weekly pattern, 1 = Monday.
            $table->unsignedSmallInteger('day_index');
            $table->boolean('is_rest_day')->default(false);
            // [{"start":"08:00","end":"12:00"},{"start":"13:00","end":"17:00"}] —
            // one entry for a normal shift, two or more for a split one. An end at
            // or before its start crosses midnight.
            $table->json('segments')->nullable();
            $table->unsignedSmallInteger('required_minutes')->default(DayRules::DEFAULT_REQUIRED_MINUTES);
            // flexible only: the window somebody must be present for…
            $table->string('core_start', 5)->nullable();
            $table->string('core_end', 5)->nullable();
            // …and the window punches are accepted in.
            $table->string('earliest_start', 5)->nullable();
            $table->string('latest_end', 5)->nullable();
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->timestamps();

            $table->unique(['work_schedule_id', 'day_index']);
            $table->index('organization_id');
        });

        Schema::create('employee_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            // Null is open-ended — the assignment in force until another replaces it.
            $table->date('effective_to')->nullable();
            // Where in a rotation this person starts: two crews on one 4-on-4-off
            // template are offset by four.
            $table->unsignedSmallInteger('cycle_offset')->default(0);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
            $table->index('organization_id');
        });

        Schema::create('shift_roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Borrow another template's pattern for the date…
            $table->foreignId('work_schedule_id')->nullable()->constrained()->nullOnDelete();
            // …or give the day its own times.
            $table->json('segments')->nullable();
            $table->unsignedSmallInteger('required_minutes')->nullable();
            $table->boolean('is_rest_day')->default(false);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['organization_id', 'date']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('default_work_schedule_id')->nullable()->after('head_id')
                ->constrained('work_schedules')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('default_work_schedule_id')->nullable()->after('timezone')
                ->constrained('work_schedules')->nullOnDelete();
        });

        $this->backfillScheduleDays();
        $this->backfillAssignments();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_work_schedule_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_work_schedule_id');
        });

        Schema::dropIfExists('shift_roster_entries');
        Schema::dropIfExists('employee_schedule_assignments');
        Schema::dropIfExists('work_schedule_days');

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn(['type', 'cycle_length_days', 'cycle_anchor_date', 'weekly_required_minutes']);
        });
    }

    /**
     * Turn each existing schedule's flat working-day list into seven day rows —
     * the same hours on each working day, a rest day everywhere else — so the
     * resolver reaches exactly the verdict the old columns did.
     */
    private function backfillScheduleDays(): void
    {
        $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $now = Carbon::now();

        foreach (DB::table('work_schedules')->orderBy('id')->cursor() as $schedule) {
            $days = json_decode((string) $schedule->work_days, true);
            $days = is_array($days) && $days !== [] ? $days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

            $start = $this->clockFace($schedule->start_time);
            $end = $this->clockFace($schedule->end_time);
            $segments = $start !== null && $end !== null
                ? json_encode([['start' => $start, 'end' => $end]])
                : null;

            $required = (int) round(((float) $schedule->required_hours) * 60);

            $rows = [];

            foreach ($weekdays as $index => $name) {
                $rows[] = [
                    'organization_id' => $schedule->organization_id,
                    'work_schedule_id' => $schedule->id,
                    'day_index' => $index + 1,
                    'is_rest_day' => ! in_array($name, $days, true),
                    'segments' => $segments,
                    'required_minutes' => $required,
                    'unpaid_break_minutes' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('work_schedule_days')->insert($rows);
        }
    }

    /**
     * Give every employee who has a schedule the open-ended assignment they have
     * been working under all along, dated from their hire date (or, failing that,
     * the day their record was created) so history is covered rather than invented.
     */
    private function backfillAssignments(): void
    {
        $now = Carbon::now();

        DB::table('employees')
            ->whereNotNull('work_schedule_id')
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($now): void {
                $rows = [];

                foreach ($employees as $employee) {
                    $from = $employee->date_hired ?? $employee->created_at ?? $now;

                    $rows[] = [
                        'organization_id' => $employee->organization_id,
                        'employee_id' => $employee->id,
                        'work_schedule_id' => $employee->work_schedule_id,
                        'effective_from' => Carbon::parse($from)->toDateString(),
                        'effective_to' => null,
                        'cycle_offset' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('employee_schedule_assignments')->insert($rows);
                }
            });
    }

    /**
     * A stored "HH:MM[:SS]" time as "HH:MM", or null when it is not set.
     */
    private function clockFace(?string $time): ?string
    {
        $time = trim((string) $time);

        return $time === '' ? null : substr($time, 0, 5);
    }
};
