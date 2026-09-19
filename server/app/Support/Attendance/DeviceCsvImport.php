<?php

namespace App\Support\Attendance;

use App\Models\AttendanceDevice;

/**
 * Reads a device's CSV export into the rows {@see DevicePunchIngestor} takes
 * (ADR 0040), for a scanner that cannot push. The columns are chosen by their
 * header once per device; everything after that is the same ingestion a push
 * goes through, so an imported punch and a pushed one are indistinguishable.
 *
 *  - **The delimiter** is whichever of comma, semicolon or tab the header uses.
 *  - **The time** is one column, or a date column and a time column joined.
 *  - **The kind of punch**, when the export says it, is read from the words and
 *    codes scanners use — "C/In", "Check Out", "Break Out", or the common
 *    numeric state (0 in, 1 out, 2 break out, 3 break in, 4 overtime in,
 *    5 overtime out). Anything else is left for the engine to infer.
 *  - **Its own id**, when the export has none, is derived from the row — the
 *    device, who, when and which punch — so importing the same file twice
 *    records each punch once.
 */
class DeviceCsvImport
{
    /** What a mapping can name a column for. */
    public const FIELDS = ['employee_ref', 'punched_at', 'date', 'time', 'type', 'external_id'];

    /** The most rows one file may carry. */
    public const MAX_ROWS = 10000;

    /** @var array<string, string> Vendor words and codes for a punch, lower-cased. */
    private const TYPE_WORDS = [
        'in' => 'clock_in', 'i' => 'clock_in', 'c/in' => 'clock_in', 'check in' => 'clock_in', 'checkin' => 'clock_in',
        'check-in' => 'clock_in', 'clock in' => 'clock_in', 'clock_in' => 'clock_in', 'time in' => 'clock_in', 'on duty' => 'clock_in',
        '0' => 'clock_in', '4' => 'clock_in', 'ot in' => 'clock_in', 'overtime in' => 'clock_in',
        'out' => 'clock_out', 'o' => 'clock_out', 'c/out' => 'clock_out', 'check out' => 'clock_out', 'checkout' => 'clock_out',
        'check-out' => 'clock_out', 'clock out' => 'clock_out', 'clock_out' => 'clock_out', 'time out' => 'clock_out', 'off duty' => 'clock_out',
        '1' => 'clock_out', '5' => 'clock_out', 'ot out' => 'clock_out', 'overtime out' => 'clock_out',
        'break out' => 'break_start', 'break start' => 'break_start', 'break_start' => 'break_start', '2' => 'break_start',
        'break in' => 'break_end', 'break end' => 'break_end', 'break_end' => 'break_end', '3' => 'break_end',
    ];

    /**
     * The file's header — what the mapping step offers to choose from.
     *
     * @return list<string>
     */
    public static function headers(string $path): array
    {
        [$header] = self::open($path);

        return $header;
    }

    /**
     * The file's rows as punches, following the mapping (`field => header`).
     *
     * @param  array<string, ?string>  $mapping
     * @return array{rows: list<array<string, ?string>>, problems: list<string>}
     */
    public static function rows(string $path, array $mapping, AttendanceDevice $device): array
    {
        [$header, $handle, $delimiter] = self::open($path);
        $problems = [];

        $index = [];

        foreach (self::FIELDS as $field) {
            $column = $mapping[$field] ?? null;

            if ($column === null) {
                continue;
            }

            $position = array_search(mb_strtolower(trim($column)), array_map(fn (string $name): string => mb_strtolower($name), $header), true);

            if ($position === false) {
                $problems[] = "The file has no column called \"{$column}\".";

                continue;
            }

            $index[$field] = $position;
        }

        if ($problems !== [] || $handle === null) {
            return ['rows' => [], 'problems' => $problems];
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $problems[] = 'Only the first '.number_format(self::MAX_ROWS).' rows were read. Split the file and import the rest.';

                break;
            }

            $cell = fn (string $field): ?string => isset($index[$field]) ? trim((string) ($cells[$index[$field]] ?? '')) : null;

            $reference = $cell('employee_ref');
            $at = $cell('punched_at') ?? trim(($cell('date') ?? '').' '.($cell('time') ?? ''));
            $type = self::type($cell('type'));

            $rows[] = [
                'external_id' => filled($cell('external_id'))
                    ? $cell('external_id')
                    : 'csv:'.sha1($device->id.'|'.mb_strtolower((string) $reference).'|'.$at.'|'.($type ?? '')),
                'employee_ref' => $reference,
                'punched_at' => $at,
                'type' => $type,
                'line' => (string) $line,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'problems' => $problems];
    }

    /**
     * A vendor's word or code for a punch, as a punch type — or null to infer.
     */
    public static function type(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : (self::TYPE_WORDS[$value] ?? null);
    }

    /**
     * Open the file past its header.
     *
     * @return array{0: list<string>, 1: resource|null, 2: string}
     */
    private static function open(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [[], null, ','];
        }

        $first = (string) fgets($handle);
        // Excel's UTF-8 byte-order mark would stick to the first header.
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;

        $delimiter = collect([',', ';', "\t"])
            ->sortByDesc(fn (string $candidate): int => substr_count($first, $candidate))
            ->first();

        $header = array_map(fn ($name): string => trim((string) $name), str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', ''));

        return [$header, $handle, $delimiter];
    }
}
