<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\HolidayRequest;
use App\Models\Holiday;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the holiday calendar: add, edit, archive, restore and
 * permanently delete a holiday, choose the sites a local one is kept at, and
 * put the movable Philippine holidays on the calendar as rules (ADR 0078).
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
     * @param  list<int>|null  $locationIds  The sites it is local to; none or null keeps it everywhere.
     */
    public function create(array $data, string $channel = '', ?array $locationIds = null): Holiday
    {
        $holiday = DB::transaction(function () use ($data, $locationIds): Holiday {
            $holiday = Holiday::create($data);

            // Saving queued the recompute already; it runs after the response,
            // by when the sites are written ({@see AttendanceInputs}).
            if ($locationIds !== null && $locationIds !== []) {
                $holiday->locations()->sync($locationIds);
            }

            return $holiday;
        });

        $this->log('created', "Added holiday \"{$holiday->name}\"".$this->where($holiday).$channel, $holiday);

        return $holiday;
    }

    /**
     * Change a holiday. Only the keys given change; the sites change only when
     * given.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $locationIds
     */
    public function update(Holiday $holiday, array $data, string $channel = '', ?array $locationIds = null): Holiday
    {
        DB::transaction(function () use ($holiday, $data, $locationIds): void {
            $holiday->update($data);

            if ($locationIds !== null) {
                $holiday->locations()->sync($locationIds);
            }
        });

        $this->log('updated', "Updated holiday \"{$holiday->name}\"".$this->where($holiday).$channel, $holiday);

        return $holiday;
    }

    /**
     * Put Holy Week and National Heroes Day on the calendar as rules, so they
     * fall on the right date every year. One already there by name (as a date
     * typed in for one year, say) is turned into its rule rather than doubled.
     *
     * @return array{added: int, converted: int}
     */
    public function adoptMovable(string $channel = ''): array
    {
        $added = 0;
        $converted = 0;

        DB::transaction(function () use (&$added, &$converted): void {
            foreach (SetupBlueprints::movableHolidays() as $movable) {
                $attributes = ['type' => $movable['type'], 'recurrence' => $movable['recurrence'], 'rule' => $movable['rule']];
                $existing = Holiday::query()->whereRaw('lower(name) = ?', [mb_strtolower($movable['name'])])->first();

                if ($existing === null) {
                    Holiday::create(['name' => $movable['name'], ...$attributes]);
                    $added++;
                } elseif ($existing->recurrence !== $movable['recurrence'] || $existing->rule != $movable['rule']) {
                    $existing->update($attributes);
                    $converted++;
                }
            }
        });

        if ($added + $converted > 0) {
            $this->log('updated', 'Put the movable holidays on the calendar ('.collect([
                $added > 0 ? "{$added} added" : null,
                $converted > 0 ? "{$converted} now worked out every year" : null,
            ])->filter()->implode(', ').')'.$channel, null, 'Holidays');
        }

        return ['added' => $added, 'converted' => $converted];
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

    /** " at Cebu Office" for a local holiday, nothing for one kept everywhere. */
    private function where(Holiday $holiday): string
    {
        $sites = $holiday->locations()->pluck('name');

        return $sites->isEmpty() ? '' : ' at '.$sites->implode(', ');
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
