<?php

namespace App\Support\DataExport;

use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Writes one Data Export archive (ADR 0066) to a working directory on local disk:
 * a file per table, grouped in a folder per dataset, the uploaded files when they
 * were asked for, a `manifest.json` describing it all for software, and a
 * `README.txt` for people.
 *
 * Rows are streamed table by table and never held in memory together, and are
 * written as stored — raw columns, archived rows included — because an export is
 * a copy of the records, not a report on them. Two things change on the way out,
 * both for CSV only: a value a spreadsheet would run as a formula is prefixed with
 * an apostrophe, and the file starts with a UTF-8 byte-order mark so Excel reads
 * names like "Peñafrancia" correctly.
 *
 * The caller owns the working directory (`dirname($result['path'])`) and deletes
 * it once the archive has been stored.
 */
final class ArchiveBuilder
{
    /** Bumped when the layout of the archive changes, so readers can tell. */
    public const VERSION = 1;

    /** Rows read per query while streaming a table. */
    private const CHUNK = 1000;

    /** Characters that make a spreadsheet treat a cell as a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * How long into a build uploads may still be copied. A build runs in the
     * PHP-FPM worker after the response, which is killed at 600 s
     * (`request_terminate_timeout`); copying thousands of punch selfies one by
     * one from the bucket can take longer than that. Past this point the rest are
     * left out and counted, leaving time to close and store the archive, so the
     * archive arrives without some files instead of not at all.
     */
    public const FILE_BUDGET_SECONDS = 420;

    /** The prefix of every build's working directory, for the sweep of orphans. */
    public const WORK_PREFIX = 'data-export-';

    public function __construct(private int $fileBudgetSeconds = self::FILE_BUDGET_SECONDS) {}

    /** Where builds write before the archive is stored. */
    public static function workRoot(): string
    {
        return storage_path('app/private/tmp');
    }

    /**
     * @return array{path: string, filename: string, size: int, summary: array<string, mixed>}
     */
    public function build(DataExport $export, Organization $organization, ?User $requester): array
    {
        $deadline = microtime(true) + $this->fileBudgetSeconds;
        $work = self::workRoot().'/'.self::WORK_PREFIX.Str::random(16);
        File::ensureDirectoryExists($work);

        $filename = self::filename($organization);
        $path = "{$work}/{$filename}";

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the archive file.');
            }

            $manifestDatasets = [];
            $summaryDatasets = [];
            $filePaths = [];

            foreach ($export->datasets as $key) {
                $dataset = DataExportCatalogue::definition($key)
                    ?? throw new InvalidArgumentException("Unknown dataset [{$key}].");

                $tables = [];

                foreach ($dataset['tables'] as $spec) {
                    $entry = "{$key}/{$spec['table']}.{$export->format}";
                    $local = "{$work}/{$key}/{$spec['table']}.{$export->format}";
                    File::ensureDirectoryExists(dirname($local));

                    [$columns, $rows, $files] = $this->writeTable($spec, $organization->id, $export->format, $local, $export->include_files);

                    $zip->addFile($local, $entry);
                    $filePaths = [...$filePaths, ...$files];
                    $tables[] = ['table' => $spec['table'], 'file' => $entry, 'rows' => $rows, 'columns' => $columns];
                }

                $manifestDatasets[] = ['key' => $key, 'label' => $dataset['label'], 'tables' => $tables];
                $summaryDatasets[$key] = [
                    'label' => $dataset['label'],
                    'rows' => array_sum(array_column($tables, 'rows')),
                    'tables' => array_column($tables, 'rows', 'table'),
                ];
            }

            $files = $export->include_files ? $this->addFiles($zip, $filePaths, $work, $deadline) : null;

            $manifest = $this->manifest($export, $organization, $requester, $manifestDatasets, $files);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $zip->addFromString('README.txt', $this->readme($export, $organization, $requester, $summaryDatasets, $files));

            if (! $zip->close()) {
                throw new RuntimeException('Could not finish writing the archive.');
            }
        } catch (Throwable $e) {
            File::deleteDirectory($work);

            throw $e;
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'size' => (int) filesize($path),
            'summary' => [
                'rows' => array_sum(array_column($summaryDatasets, 'rows')),
                'datasets' => $summaryDatasets,
                'files' => $files,
            ],
        ];
    }

    /**
     * Stream one table into a CSV or JSON file.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: list<string>, 1: int, 2: list<string>} The columns written, the row count, and the file paths the rows name.
     */
    private function writeTable(array $spec, int $organizationId, string $format, string $local, bool $collectFiles): array
    {
        [$columns, $booleans, $jsons] = $this->columns($spec);
        $fileColumns = $collectFiles ? ($spec['files'] ?? []) : [];

        $handle = fopen($local, 'wb') ?: throw new RuntimeException("Could not write {$spec['table']}.");
        $rows = 0;
        $files = [];

        if ($format === 'csv') {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns, escape: '');
        } else {
            fwrite($handle, '[');
        }

        foreach ($this->rows($spec, $organizationId, $columns) as $row) {
            $row = (array) $row;

            foreach ($fileColumns as $column) {
                if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                    $files[] = $row[$column];
                }
            }

            if ($format === 'csv') {
                fputcsv($handle, array_map(fn (string $column): string => $this->csvValue($row[$column] ?? null, isset($booleans[$column])), $columns), escape: '');
            } else {
                $record = [];

                foreach ($columns as $column) {
                    $record[$column] = $this->jsonValue($row[$column] ?? null, isset($booleans[$column]), isset($jsons[$column]));
                }

                fwrite($handle, ($rows === 0 ? "\n" : ",\n").json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            }

            $rows++;
        }

        if ($format === 'json') {
            fwrite($handle, $rows === 0 ? "]\n" : "\n]\n");
        }

        fclose($handle);

        return [$columns, $rows, $files];
    }

    /**
     * The columns a table exports, in table order, and which of them hold
     * booleans and JSON — read from the schema, since the rows come back raw.
     *
     * Driver-aware: Postgres reports `bool` and `json`/`jsonb`; SQLite stores a
     * boolean as `tinyint(1)` and JSON as plain text, so there JSON columns stay
     * strings.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: list<string>, 1: array<string, true>, 2: array<string, true>}
     */
    private function columns(array $spec): array
    {
        $exclude = $spec['exclude'] ?? [];
        $columns = [];
        $booleans = [];
        $jsons = [];

        foreach (Schema::getColumns($spec['table']) as $column) {
            $name = $column['name'];

            if (in_array($name, $exclude, true)) {
                continue;
            }

            $columns[] = $name;
            $type = strtolower((string) $column['type_name']);

            if (in_array($type, ['bool', 'boolean'], true) || strtolower((string) $column['type']) === 'tinyint(1)') {
                $booleans[$name] = true;
            }

            if (in_array($type, ['json', 'jsonb'], true)) {
                $jsons[$name] = true;
            }
        }

        return [$columns, $booleans, $jsons];
    }

    /**
     * The organisation's rows of a table, a chunk at a time.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $columns
     * @return iterable<object>
     */
    private function rows(array $spec, int $organizationId, array $columns): iterable
    {
        $table = $spec['table'];
        $query = DataExportCatalogue::query($spec, $organizationId)
            ->select(array_map(fn (string $column): string => "{$table}.{$column}", $columns));

        // A pivot has no id to page by: walk it in its key order instead.
        if (isset($spec['order'])) {
            foreach ($spec['order'] as $column) {
                $query->orderBy("{$table}.{$column}");
            }

            return $query->lazy(self::CHUNK);
        }

        return $query->lazyById(self::CHUNK, "{$table}.id", 'id');
    }

    /**
     * One value as a CSV cell.
     */
    private function csvValue(mixed $value, bool $boolean): string
    {
        if ($value === null) {
            return '';
        }

        if ($boolean || is_bool($value)) {
            return (bool) $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * One value as JSON: booleans as booleans, JSON columns as the structure they
     * hold, everything else as stored (decimals stay text, so none of their
     * precision is lost).
     */
    private function jsonValue(mixed $value, bool $boolean, bool $json): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($boolean || is_bool($value)) {
            return (bool) $value;
        }

        if ($json && is_string($value) && json_validate($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    /**
     * Copy the uploaded files the rows name into `files/`, under the path they
     * are stored at. A file that cannot be found or read is counted as missing,
     * not fatal (the `public` disk does not throw); a link to somewhere else (a
     * photo kept as a URL) is not ours to copy; and once the deadline passes the
     * rest are counted as skipped.
     *
     * Copies are stored, not deflated: photos and PDFs are compressed already.
     *
     * @param  list<string>  $paths
     * @return array{count: int, bytes: int, missing: int, skipped: int}
     */
    private function addFiles(ZipArchive $zip, array $paths, string $work, float $deadline): array
    {
        $disk = Storage::disk('public');
        $count = 0;
        $bytes = 0;
        $missing = 0;
        $skipped = 0;
        $index = 0;

        foreach (array_unique($paths) as $path) {
            if (Str::startsWith($path, ['http://', 'https://'])) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                $skipped++;

                continue;
            }

            $path = ltrim(str_replace('\\', '/', $path), '/');

            // Never let a stored value write outside `files/`.
            if ($path === '' || in_array('..', explode('/', $path), true)) {
                $missing++;

                continue;
            }

            try {
                // One round trip: a missing file reads as null.
                $stream = $disk->readStream($path);
            } catch (Throwable) {
                $stream = null;
            }

            if (! is_resource($stream)) {
                $missing++;

                continue;
            }

            $local = "{$work}/files/".($index++);
            File::ensureDirectoryExists(dirname($local));
            $target = fopen($local, 'wb') ?: throw new RuntimeException('Could not copy an uploaded file.');
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);

            $zip->addFile($local, "files/{$path}");
            $zip->setCompressionName("files/{$path}", ZipArchive::CM_STORE);
            $count++;
            $bytes += (int) filesize($local);
        }

        return ['count' => $count, 'bytes' => $bytes, 'missing' => $missing, 'skipped' => $skipped];
    }

    /**
     * The archive described for software.
     *
     * @param  list<array<string, mixed>>  $datasets
     * @param  array{count: int, bytes: int, missing: int, skipped: int}|null  $files
     * @return array<string, mixed>
     */
    private function manifest(DataExport $export, Organization $organization, ?User $requester, array $datasets, ?array $files): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => now()->utc()->toIso8601String(),
            'timestamps' => 'Stored times are UTC.',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'timezone' => $organization->timezone,
            ],
            'requested_by' => $requester === null ? null : [
                'id' => $requester->id,
                'name' => $requester->full_name,
                'email' => $requester->email,
            ],
            'format' => $export->format,
            'datasets' => $datasets,
            'files' => $files === null ? ['included' => false] : ['included' => true, 'folder' => 'files/', ...$files],
            'left_out' => [
                'columns' => collect(DataExportCatalogue::DATASETS)
                    ->flatMap(fn (array $dataset): array => $dataset['tables'])
                    ->filter(fn (array $table): bool => ($table['exclude'] ?? []) !== [])
                    ->mapWithKeys(fn (array $table): array => [$table['table'] => $table['exclude']])
                    ->all(),
                'tables' => DataExportCatalogue::EXCLUDED,
            ],
        ];
    }

    /**
     * The archive described for the person who opens it.
     *
     * @param  array<string, array{label: string, rows: int, tables: array<string, int>}>  $datasets
     * @param  array{count: int, bytes: int, missing: int, skipped: int}|null  $files
     */
    private function readme(DataExport $export, Organization $organization, ?User $requester, array $datasets, ?array $files): string
    {
        $generated = now()->setTimezone($organization->timezone ?: config('app.timezone'));
        $extension = $export->format;

        $lines = [
            'SYNAPSE DATA EXPORT',
            str_repeat('=', 19),
            '',
            "Company:       {$organization->name}",
            'Generated:     '.$generated->format('F j, Y g:i A T'),
            'Requested by:  '.($requester === null ? '—' : "{$requester->full_name} <{$requester->email}>"),
            'Format:        '.strtoupper($extension),
            '',
            'This archive is a copy of your company\'s records in SYNAPSE. It holds personal',
            'information about your people: keep it somewhere safe, share it only with',
            'those entitled to it under the Data Privacy Act, and delete it when you no',
            'longer need it.',
            '',
            'WHAT IS INSIDE',
            '--------------',
        ];

        foreach ($datasets as $key => $dataset) {
            $lines[] = '';
            $lines[] = "{$dataset['label']} ({$key}/)";

            foreach ($dataset['tables'] as $table => $rows) {
                $lines[] = sprintf('  %-40s %s', "{$table}.{$extension}", number_format($rows).' '.Str::plural('row', $rows));
            }
        }

        if ($files !== null) {
            $lines[] = '';
            $lines[] = 'Uploaded files (files/)';
            $lines[] = '  '.number_format($files['count']).' '.Str::plural('file', $files['count']).', under the same path the records name.';

            if ($files['missing'] > 0) {
                $lines[] = '  '.number_format($files['missing']).' could not be found or read, and are not included.';
            }

            if ($files['skipped'] > 0) {
                $lines[] = '  '.number_format($files['skipped']).($files['skipped'] === 1 ? ' file was' : ' files were').' not included: copying them would have taken longer';
                $lines[] = '  than an export may. Export fewer kinds of record with their files.';
            }
        }

        $lines = [...$lines, '', 'READING IT', '----------', ''];

        $lines = [...$lines, ...match ($extension) {
            'csv' => [
                '- Each table is a CSV file in UTF-8 with a header row; Excel, Numbers and',
                '  Google Sheets open it directly.',
                '- A text value beginning with = + - or @ is written with a leading apostrophe,',
                '  so a spreadsheet shows it rather than running it as a formula.',
                '- Yes/no values read "true" or "false"; empty cells are empty values.',
            ],
            default => [
                '- Each table is a JSON array of records, one object per row.',
                '- Values are as stored: yes/no values are booleans, structured columns are',
                '  nested objects, and decimal numbers are text so no precision is lost.',
            ],
        }];

        $lines = [
            ...$lines,
            '- Every table keeps its ids, so records link up: employees.department_id is a',
            '  departments.id, and so on. Archived records are included, with deleted_at set.',
            '- Stored times are UTC. Your company keeps '.($organization->timezone ?: config('app.timezone')).'.',
            '- manifest.json lists every file, its columns and its row count.',
            '',
            'NOT INCLUDED',
            '------------',
            '',
            '- Passwords, two-step sign-in secrets, invitation links and codes, and the join',
            '  code: they let somebody sign in, and are not records.',
            '- Each person\'s own conversations with the assistant, and their notifications.',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * `<company>-data-export-<date>-<time>.zip`, on the company's clock — also
     * shown on the screen as the name the next archive will have.
     */
    public static function filename(Organization $organization): string
    {
        $stamp = now()->setTimezone($organization->timezone ?: config('app.timezone'))->format('Y-m-d-His');

        return (Str::slug($organization->slug ?: $organization->name) ?: 'synapse').'-data-export-'.$stamp.'.zip';
    }
}
