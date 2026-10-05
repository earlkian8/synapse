<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\DataExport\DataExportCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archive of the organisation's records that somebody asked for (ADR 0066):
 * the datasets in it ({@see DataExportCatalogue}), its
 * format, whether the uploaded files came along, and how far it got. Addressed by
 * hashid.
 *
 * Queued → building → ready, or failed. A ready archive is kept for
 * {@see self::RETENTION_DAYS} days and then deleted by `data-export:prune`, which
 * leaves the row as history with the status expired. An archive past its date is
 * treated as expired at once, whether or not the prune has run yet.
 */
class DataExport extends Model
{
    use BelongsToOrganization, HasHashid;

    public const QUEUED = 'queued';

    public const BUILDING = 'building';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const STATUSES = [self::QUEUED, self::BUILDING, self::READY, self::FAILED, self::EXPIRED];

    public const FORMATS = ['csv', 'json'];

    /** How long a finished archive may be downloaded. */
    public const RETENTION_DAYS = 7;

    /**
     * How long an export may stay queued or building before it is taken to have
     * died — a worker killed mid-build, or a request that never got to run it.
     */
    public const STALE_AFTER_MINUTES = 60;

    /** What a build that died is said to have done. */
    public const STALE_ERROR = 'The export stopped before it finished. Try again.';

    protected $fillable = [
        'organization_id',
        'requested_by',
        'status',
        'format',
        'datasets',
        'include_files',
        'disk',
        'path',
        'filename',
        'size_bytes',
        'summary',
        'error',
        'started_at',
        'completed_at',
        'expires_at',
        'download_count',
        'last_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            // Compared strictly when deciding who may download or delete.
            'organization_id' => 'integer',
            'requested_by' => 'integer',
            'datasets' => 'array',
            'include_files' => 'boolean',
            'size_bytes' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'download_count' => 'integer',
            'last_downloaded_at' => 'datetime',
        ];
    }

    /**
     * The person who asked for it — the only one who may download it. Archived
     * accounts included, so the history still says who it was.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /**
     * Still queued or building — and not for so long that it must have died.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::QUEUED, self::BUILDING], true) && ! $this->isStale();
    }

    /**
     * Queued or building for longer than any build takes: its process died. The
     * prune records it as failed; until then it reads as failed already.
     */
    public function isStale(): bool
    {
        if (! in_array($this->status, [self::QUEUED, self::BUILDING], true)) {
            return false;
        }

        $since = $this->started_at ?? $this->created_at;

        return $since !== null && $since->lte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Finished and still within its keep-until date.
     */
    public function isDownloadable(): bool
    {
        return $this->status === self::READY
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * The status as the screen should show it, before the prune has caught up:
     * an archive past its date reads as expired, a build that died as failed.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->status === self::READY && ! $this->isDownloadable() => self::EXPIRED,
            $this->isStale() => self::FAILED,
            default => $this->status,
        };
    }

    /**
     * Exports still being prepared, unless they have been at it so long they
     * must have died.
     *
     * @param  Builder<DataExport>  $query
     */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::BUILDING])
            ->where(fn (Builder $query) => $query
                ->where('started_at', '>', now()->subMinutes(self::STALE_AFTER_MINUTES))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('started_at')
                    ->where('created_at', '>', now()->subMinutes(self::STALE_AFTER_MINUTES))));
    }

    /**
     * Exports queued or building for longer than any build takes.
     *
     * @param  Builder<DataExport>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::BUILDING])
            ->where(fn (Builder $query) => $query
                ->where('started_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('started_at')
                    ->where('created_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))));
    }

    /**
     * Finished archives past their keep-until date.
     *
     * @param  Builder<DataExport>  $query
     */
    public function scopePastRetention(Builder $query): void
    {
        $query->where('status', self::READY)->where('expires_at', '<=', now());
    }

    /**
     * Newest first.
     *
     * @param  Builder<DataExport>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
