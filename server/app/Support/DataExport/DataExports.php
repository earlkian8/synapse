<?php

namespace App\Support\DataExport;

use App\Jobs\BuildDataExport;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\Tenancy;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The life of a Data Export (ADR 0066): asked for, built, downloaded, deleted,
 * and expired. Everything that changes an export goes through here, so the
 * screen, the build job and the scheduled prune keep the same rules.
 *
 * Who may do what:
 *
 *  - **Ask** — `data-export.create`, for datasets the asker may view
 *    ({@see DataExportCatalogue::allows()}); one export at a time per workspace.
 *  - **Download** — only the person who asked, while it is kept, and only while
 *    they can still view everything in it: the archive was built from their
 *    access, and a narrowed role narrows what they may take away.
 *  - **Delete** — anybody with `data-export.create`: taking an archive away
 *    never exposes anything.
 */
class DataExports
{
    /** The disk archives are written to. Private: see config/filesystems.php. */
    public const DISK = 'exports';

    public function __construct(private ArchiveBuilder $builder) {}

    /**
     * Queue an export of the current workspace for a user, and start building it
     * once the response has gone.
     *
     * @param  list<string>  $datasets
     *
     * @throws DataExportException when another export is being prepared.
     */
    public function request(User $user, array $datasets, string $format, bool $includeFiles): DataExport
    {
        // Two clicks at once must not both pass the one-at-a-time check below.
        $lock = Cache::lock('data-export-request:'.app(Tenancy::class)->id(), 10);

        if (! $lock->get()) {
            throw new DataExportException('An export is already being prepared. Try again once it is ready.');
        }

        try {
            return $this->queue($user, $datasets, $format, $includeFiles);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $datasets
     */
    private function queue(User $user, array $datasets, string $format, bool $includeFiles): DataExport
    {
        $running = DataExport::query()->inProgress()->with('requester')->first();

        if ($running !== null) {
            $who = $running->requester?->is($user) ? 'Your earlier export' : 'An export'.($running->requester ? " by {$running->requester->full_name}" : '');

            throw new DataExportException("{$who} is already being prepared. Try again once it is ready.");
        }

        // In catalogue order, whatever order they were ticked in.
        $datasets = array_values(array_intersect(DataExportCatalogue::keys(), $datasets));

        $export = DataExport::create([
            'requested_by' => $user->id,
            'status' => DataExport::QUEUED,
            'format' => $format,
            'datasets' => $datasets,
            'include_files' => $includeFiles,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: 'Requested a data export of '.$this->describe($datasets).($includeFiles ? ', with uploaded files' : ''),
            subject: $export,
            properties: ['datasets' => $datasets, 'format' => $format, 'include_files' => $includeFiles],
            logName: 'data-export',
            subjectLabel: 'Data export',
        );

        // Built in this process after the response — for the reason
        // RecomputeAttendanceRange is: the app is often served without
        // `queue:work`, and an export that silently never starts is worse than
        // one that holds a worker for a minute.
        BuildDataExport::dispatchAfterResponse($export->id);

        return $export;
    }

    /**
     * Build a queued export: write the archive, store it, and tell whoever asked.
     * Claims the export first, so a second run of the same job does nothing.
     */
    public function build(DataExport $export): void
    {
        $claimed = DataExport::withoutGlobalScopes()
            ->whereKey($export->id)
            ->where('status', DataExport::QUEUED)
            ->update(['status' => DataExport::BUILDING, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $export->refresh();
        $organization = Organization::query()->find($export->organization_id);
        $requester = $export->requested_by ? User::withTrashed()->find($export->requested_by) : null;

        if ($organization === null) {
            $this->fail($export, $requester, 'The workspace no longer exists.');

            return;
        }

        app(Tenancy::class)->runFor($organization, function () use ($export, $organization, $requester): void {
            $built = null;
            $path = null;

            // An archive of a large workspace can take a while to write.
            set_time_limit(0);

            try {
                $built = $this->snapshot(fn (): array => $this->builder->build($export, $organization, $requester));

                $path = "organization-{$organization->id}/".Str::uuid().'.zip';
                $stream = fopen($built['path'], 'rb');
                Storage::disk(self::DISK)->writeStream($path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $export->forceFill([
                    'status' => DataExport::READY,
                    'disk' => self::DISK,
                    'path' => $path,
                    'filename' => $built['filename'],
                    'size_bytes' => $built['size'],
                    'summary' => $built['summary'],
                    'error' => null,
                    'completed_at' => now(),
                    'expires_at' => now()->addDays(DataExport::RETENTION_DAYS),
                ])->save();
            } catch (Throwable $e) {
                report($e);

                // Possibly stored, but its row could not say so: nothing would
                // ever find it to delete it.
                if ($path !== null) {
                    rescue(fn () => Storage::disk(self::DISK)->delete($path), report: false);
                }

                $this->fail($export, $requester, 'Something went wrong while writing the archive. Try again, and if it keeps failing, contact your administrator.');

                return;
            } finally {
                if ($built !== null) {
                    File::deleteDirectory(dirname($built['path']));
                }
            }

            if ($requester !== null && ! $requester->trashed()) {
                Notifier::toUser(
                    user: $requester,
                    title: 'Your data export is ready',
                    body: 'The archive of '.$this->describe($export->datasets).' ('.Number::fileSize($export->size_bytes, precision: 1).') can be downloaded until '
                        .$export->expires_at->setTimezone($organization->timezone ?: config('app.timezone'))->format('F j, g:i A').'.',
                    url: '/system/data-export',
                    level: 'success',
                    category: 'data-export',
                );
            }
        });
    }

    /**
     * Whether a user may download an export now.
     */
    public function canDownload(User $user, DataExport $export): bool
    {
        return $export->isDownloadable()
            && $export->requested_by === $user->id
            && $user->can('data-export.create')
            && collect($export->datasets)->every(fn (string $key): bool => DataExportCatalogue::allows($user, $key));
    }

    /**
     * Whether a user may delete an export now.
     */
    public function canDelete(User $user, DataExport $export): bool
    {
        return $user->can('data-export.create') && ! $export->isInProgress();
    }

    /**
     * Stream an archive to the person who asked for it, and record that they did.
     *
     * @throws DataExportException when its file is no longer on the disk.
     */
    public function download(DataExport $export): StreamedResponse
    {
        $disk = Storage::disk($export->disk ?: self::DISK);

        if ($export->path === null || ! $disk->exists($export->path)) {
            $export->forceFill(['status' => DataExport::EXPIRED])->save();

            throw new DataExportException('This archive is no longer available. Prepare a new export.');
        }

        $export->forceFill([
            'download_count' => $export->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        ActivityLogger::log(
            event: 'downloaded',
            description: 'Downloaded the data export of '.$this->describe($export->datasets),
            subject: $export,
            properties: ['datasets' => $export->datasets, 'size_bytes' => $export->size_bytes],
            logName: 'data-export',
            subjectLabel: 'Data export',
        );

        return $disk->download($export->path, $export->filename ?: 'synapse-export.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Delete an export and its archive.
     *
     * @throws DataExportException while it is still being prepared.
     */
    public function delete(DataExport $export): void
    {
        if ($export->isInProgress()) {
            throw new DataExportException('This export is still being prepared. Delete it once it is ready.');
        }

        $this->deleteFile($export);
        $export->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Deleted the data export of '.$this->describe($export->datasets),
            properties: ['datasets' => $export->datasets],
            logName: 'data-export',
            subjectLabel: 'Data export',
        );
    }

    /**
     * Delete every archive past its keep-until date and close every export that
     * never finished, across all workspaces (the scheduled `data-export:prune`).
     *
     * @return array{expired: int, failed: int}
     */
    public function prune(): array
    {
        $expired = 0;
        $failed = 0;

        // By id, not by offset: each row leaves the scope it was found by.
        DataExport::withoutGlobalScopes()->pastRetention()->lazyById()->each(function (DataExport $export) use (&$expired): void {
            $this->deleteFile($export);
            $export->forceFill(['status' => DataExport::EXPIRED])->save();
            $expired++;
        });

        DataExport::withoutGlobalScopes()->stale()->with('requester')->lazyById()->each(function (DataExport $export) use (&$failed): void {
            $this->fail($export, $export->requester, DataExport::STALE_ERROR);
            $failed++;
        });

        $this->sweepWorkingFiles();

        return ['expired' => $expired, 'failed' => $failed];
    }

    /**
     * "Employees, Leave and 2 more" — the datasets as people read them.
     *
     * @param  list<string>  $datasets
     */
    public function describe(array $datasets): string
    {
        $labels = collect($datasets)
            ->map(fn (string $key): string => DataExportCatalogue::definition($key)['label'] ?? $key)
            ->values();

        if ($labels->count() > 3) {
            return $labels->take(2)->implode(', ').' and '.($labels->count() - 2).' more';
        }

        return $labels->join(', ', ' and ');
    }

    /**
     * Read the whole archive as of one moment, so its tables agree with each
     * other even while people keep working: one REPEATABLE READ, READ ONLY
     * transaction on Postgres. Elsewhere, or inside a transaction that is
     * already open (whose isolation can no longer change), rows are read as
     * they come.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    private function snapshot(Closure $read): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() > 0) {
            return $read();
        }

        return $connection->transaction(function () use ($connection, $read): mixed {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }

    /**
     * Delete working directories a build left behind when its process was
     * killed before it could clean up — older than any build runs.
     */
    private function sweepWorkingFiles(): void
    {
        $cutoff = now()->subMinutes(DataExport::STALE_AFTER_MINUTES)->getTimestamp();

        foreach (File::glob(ArchiveBuilder::workRoot().'/'.ArchiveBuilder::WORK_PREFIX.'*', GLOB_ONLYDIR) as $directory) {
            if (File::lastModified($directory) < $cutoff) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * Record a build that could not finish, and tell whoever asked.
     */
    private function fail(DataExport $export, ?User $requester, string $reason): void
    {
        $export->forceFill([
            'status' => DataExport::FAILED,
            'error' => $reason,
            'completed_at' => now(),
        ])->save();

        if ($requester !== null && ! $requester->trashed()) {
            Notifier::toUser(
                user: $requester,
                title: 'Your data export could not be prepared',
                body: $reason,
                url: '/system/data-export',
                level: 'error',
                category: 'data-export',
            );
        }
    }

    /**
     * Remove an export's archive from its disk, if it is still there.
     */
    private function deleteFile(DataExport $export): void
    {
        if ($export->path === null) {
            return;
        }

        try {
            Storage::disk($export->disk ?: self::DISK)->delete($export->path);
        } catch (Throwable $e) {
            // A file already gone is the outcome wanted; anything else is worth knowing.
            report($e);
        }
    }
}
