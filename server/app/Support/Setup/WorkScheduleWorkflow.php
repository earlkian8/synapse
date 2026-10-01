<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the shift templates (ADR 0037): create, edit, archive,
 * restore and permanently delete a schedule, and choose the company's default
 * hours.
 *
 * The Work Schedule & Holidays screen and the assistant both come through here,
 * so a schedule is written and recorded the same way whoever asked. Validation
 * is {@see WorkScheduleRequest} (its rules and
 * {@see WorkScheduleRequest::validatePattern()}), which both run first; the day
 * pattern itself is written by {@see SchedulePatternWriter}, the one writer of
 * a template's cycle.
 *
 * Editing a schedule changes the days nobody has recorded yet; a recorded day
 * keeps the shift it opened with until HR re-applies it.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class WorkScheduleWorkflow
{
    public function __construct(private readonly SchedulePatternWriter $patterns) {}

    /**
     * @param  array<string, mixed>  $attributes  The schedule's own columns.
     * @param  list<array<string, mixed>>  $days  One entry per day of the cycle.
     */
    public function create(array $attributes, array $days, string $channel = ''): WorkSchedule
    {
        $schedule = DB::transaction(function () use ($attributes, $days): WorkSchedule {
            $schedule = WorkSchedule::create($attributes);
            $this->patterns->write($schedule, $days);

            return $schedule;
        });

        $this->log('created', "Created work schedule \"{$schedule->name}\"{$channel}", $schedule);

        return $schedule;
    }

    /**
     * Change a schedule. Only the columns given change; the day pattern is
     * rewritten only when one is given.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $days
     */
    public function update(WorkSchedule $schedule, array $attributes, ?array $days = null, string $channel = ''): WorkSchedule
    {
        DB::transaction(function () use ($schedule, $attributes, $days): void {
            $schedule->update($attributes);

            if ($days !== null) {
                $this->patterns->write($schedule, $days);
            }
        });

        $this->log('updated', "Updated work schedule \"{$schedule->name}\"{$channel}", $schedule);

        return $schedule;
    }

    /**
     * Archive a schedule. People assigned to it keep it — every relation to a
     * schedule reads archived ones too — and so does a company default.
     */
    public function archive(WorkSchedule $schedule, string $channel = ''): void
    {
        $name = $schedule->name;
        $schedule->delete();

        $this->log('archived', "Archived work schedule \"{$name}\"{$channel}", null, $name);
    }

    public function restore(WorkSchedule $schedule, string $channel = ''): void
    {
        $schedule->restore();

        $this->log('restored', "Restored work schedule \"{$schedule->name}\"{$channel}", $schedule);
    }

    /**
     * @throws WorkScheduleException when people are still assigned to it
     */
    public function forceDelete(WorkSchedule $schedule, string $channel = ''): void
    {
        if ($schedule->employees()->exists()) {
            throw new WorkScheduleException('This schedule is assigned to employees and cannot be permanently deleted.');
        }

        $name = $schedule->name;
        $schedule->forceDelete();

        $this->log('deleted', "Permanently deleted work schedule \"{$name}\"{$channel}", null, $name);
    }

    /**
     * Choose the company's default hours — what anyone with no assignment and no
     * department default works (ADR 0037). Null clears it, which drops those
     * people to the built-in Mon–Fri fallback.
     */
    public function setDefault(?WorkSchedule $schedule, string $channel = ''): void
    {
        $organization = app(Tenancy::class)->organization();

        abort_if($organization === null, 403);

        $organization->forceFill(['default_work_schedule_id' => $schedule?->id])->save();

        ActivityLogger::log(
            event: 'updated',
            description: $schedule !== null
                ? "Set \"{$schedule->name}\" as the company's default schedule{$channel}"
                : "Cleared the company's default schedule{$channel}",
            subject: $schedule,
            logName: 'company-setup',
            subjectLabel: $schedule?->name ?? 'Work schedules',
        );
    }

    private function log(string $event, string $description, ?WorkSchedule $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'company-setup',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
