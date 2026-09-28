<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\HolidayRequest;
use App\Models\Holiday;
use App\Support\ActivityLogger;

/**
 * Everything that changes the holiday calendar: add, edit, archive, restore and
 * permanently delete a holiday.
 *
 * The Work Schedule & Holidays screen and the assistant both come through here,
 * so a holiday is written and recorded the same way whoever asked. Validation
 * is {@see HolidayRequest}, which both run first.
 *
 * A holiday decides whether a date is a working day — Leave does not charge it,
 * Attendance records it as a holiday — but only for days not yet recorded: a
 * recorded day keeps what it was judged by until HR re-applies it.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class HolidayWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): Holiday
    {
        $holiday = Holiday::create($data);

        $this->log('created', "Added holiday \"{$holiday->name}\"{$channel}", $holiday);

        return $holiday;
    }

    /**
     * Change a holiday. Only the keys given change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Holiday $holiday, array $data, string $channel = ''): Holiday
    {
        $holiday->update($data);

        $this->log('updated', "Updated holiday \"{$holiday->name}\"{$channel}", $holiday);

        return $holiday;
    }

    public function archive(Holiday $holiday, string $channel = ''): void
    {
        $name = $holiday->name;
        $holiday->delete();

        $this->log('archived', "Archived holiday \"{$name}\"{$channel}", null, $name);
    }

    public function restore(Holiday $holiday, string $channel = ''): void
    {
        $holiday->restore();

        $this->log('restored', "Restored holiday \"{$holiday->name}\"{$channel}", $holiday);
    }

    public function forceDelete(Holiday $holiday, string $channel = ''): void
    {
        $name = $holiday->name;
        $holiday->forceDelete();

        $this->log('deleted', "Permanently deleted holiday \"{$name}\"{$channel}", null, $name);
    }

    private function log(string $event, string $description, ?Holiday $subject, ?string $label = null): void
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
