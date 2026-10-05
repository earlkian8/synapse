import {
    AlertCircle,
    Download,
    History,
    Paperclip,
    Trash2,
} from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    STATUS_META,
    formatBytes,
    formatCount,
    formatDateTime,
    formatUntil,
    isInProgress,
} from '../constants';
import { dataExportRoutes } from '../routes';
import type { DataExportItem } from '../types';

type Props = {
    exports: DataExportItem[];
    onDelete: (item: DataExportItem) => void;
};

/** "Employees, Leave and 3 more". */
export function describeDatasets(item: DataExportItem): string {
    const labels = item.datasets.map((dataset) => dataset.label);

    if (labels.length > 3) {
        return `${labels.slice(0, 2).join(', ')} and ${labels.length - 2} more`;
    }

    if (labels.length <= 1) {
        return labels[0] ?? 'Nothing';
    }

    return `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`;
}

export function ExportStatusBadge({ item }: { item: DataExportItem }) {
    const meta = STATUS_META[item.status];

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium',
                meta.className,
            )}
        >
            {isInProgress(item.status) && <Spinner className="size-3" />}
            {meta.label}
        </span>
    );
}

/** Every archive asked for in this workspace, newest first. */
export function ExportHistory({ exports, onDelete }: Props) {
    return (
        <section
            aria-labelledby="export-history"
            className="flex flex-col gap-3"
        >
            <h2
                id="export-history"
                className="flex items-center gap-2 text-sm font-semibold"
            >
                <History className="size-4 text-muted-foreground" />
                Archives
            </h2>

            {exports.length === 0 ? (
                <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    No archives yet. The ones you prepare appear here, to
                    download while they are kept.
                </p>
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                    {exports.map((item) => (
                        <ExportRow
                            key={item.hashid}
                            item={item}
                            onDelete={() => onDelete(item)}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function ExportRow({
    item,
    onDelete,
}: {
    item: DataExportItem;
    onDelete: () => void;
}) {
    const who = item.requested_by;

    return (
        <li className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <PersonAvatar
                    name={who?.name ?? 'Unknown'}
                    initials={who?.initials ?? '?'}
                    photo={who?.avatar}
                    className="mt-0.5 size-8"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate text-sm font-medium">
                            {describeDatasets(item)}
                        </p>
                        <ExportStatusBadge item={item} />
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {item.is_mine ? 'You' : (who?.name ?? 'Someone')},{' '}
                        {formatDateTime(item.created_at)}
                    </p>
                    <Details item={item} />
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1 self-end sm:self-center">
                {item.can_download && (
                    <Button asChild size="sm" variant="outline">
                        <a href={dataExportRoutes.download(item.hashid)}>
                            <Download className="size-4" />
                            Download
                        </a>
                    </Button>
                )}
                {item.can_delete && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground hover:text-destructive"
                        onClick={onDelete}
                        aria-label="Delete archive"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )}
            </div>
        </li>
    );
}

/** The one line that matters for the archive's state. */
function Details({ item }: { item: DataExportItem }) {
    if (isInProgress(item.status)) {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                {item.is_mine
                    ? 'Writing your archive. You will get a notification when it is ready.'
                    : 'Being written.'}
            </p>
        );
    }

    if (item.status === 'failed') {
        return (
            <p className="mt-1 flex items-start gap-1.5 text-xs text-rose-700 dark:text-rose-400">
                <AlertCircle className="mt-px size-3.5 shrink-0" />
                {item.error ?? 'The export could not be prepared.'}
            </p>
        );
    }

    const facts = [
        item.format.toUpperCase(),
        formatBytes(item.size_bytes),
        item.rows !== null ? `${formatCount(item.rows)} records` : null,
        item.files
            ? `${formatCount(item.files.count)} uploaded ${item.files.count === 1 ? 'file' : 'files'}`
            : null,
    ].filter(Boolean);

    return (
        <div className="mt-1 flex flex-col gap-0.5 text-xs text-muted-foreground">
            <p className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                {facts.map((fact) => (
                    <span key={fact}>{fact}</span>
                ))}
                {item.include_files && !item.files && (
                    <span className="inline-flex items-center gap-1">
                        <Paperclip className="size-3" />
                        With uploads
                    </span>
                )}
            </p>
            {item.status === 'ready' && (
                <p>
                    {item.is_mine
                        ? `Kept until ${formatDateTime(item.expires_at)} (${formatUntil(item.expires_at)})`
                        : `Only ${item.requested_by?.name ?? 'the person who asked for it'} can download it`}
                    {item.download_count > 0 &&
                        `, downloaded ${item.download_count === 1 ? 'once' : `${item.download_count} times`}`}
                </p>
            )}
            {item.status === 'ready' && item.is_mine && !item.can_download && (
                <p className="text-amber-700 dark:text-amber-400">
                    Your access has changed since it was prepared, so it can no
                    longer be downloaded.
                </p>
            )}
            {item.status === 'expired' && (
                <p>No longer kept, so it cannot be downloaded.</p>
            )}
            {item.files && (item.files.skipped ?? 0) > 0 && (
                <p className="text-amber-700 dark:text-amber-400">
                    {formatCount(item.files.skipped ?? 0)} uploaded{' '}
                    {item.files.skipped === 1 ? 'file was' : 'files were'} left
                    out: copying them would have taken too long. Export fewer
                    kinds of record with their files.
                </p>
            )}
            {item.files && item.files.missing > 0 && (
                <p className="text-amber-700 dark:text-amber-400">
                    {formatCount(item.files.missing)} uploaded{' '}
                    {item.files.missing === 1 ? 'file' : 'files'} could not be
                    found or read, and are not included.
                </p>
            )}
        </div>
    );
}
