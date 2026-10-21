import { useQuery } from '@tanstack/react-query';
import { ImageOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { apiBlob } from '@/lib/api';

/**
 * A screenshot on a report. The ERP streams it only to an admin's token, so it
 * is fetched with the session and shown from an object URL — never linked, so
 * no address to it outlives the session. Opening it shows it full size in a
 * new tab, from the same blob.
 */
export function Screenshot({
    reportId,
    attachment,
}: {
    reportId: number;
    attachment: {
        id: number;
        name: string;
        width: number | null;
        height: number | null;
    };
}) {
    const blob = useQuery({
        queryKey: ['problem-report-screenshot', reportId, attachment.id],
        queryFn: ({ signal }) =>
            apiBlob(
                `/problem-reports/${reportId}/attachments/${attachment.id}`,
                signal,
            ),
        staleTime: Infinity,
        gcTime: 5 * 60_000,
    });
    const url = useObjectUrl(blob.data ?? null);

    return (
        <figure className="w-36 shrink-0">
            <button
                type="button"
                disabled={!url}
                onClick={() => url && window.open(url, '_blank', 'noopener')}
                className="block h-24 w-full overflow-hidden rounded-lg border border-sidebar-border/70 bg-muted transition-opacity hover:opacity-90 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default dark:border-sidebar-border"
                aria-label={`Open ${attachment.name} full size`}
            >
                {url ? (
                    <img
                        src={url}
                        alt=""
                        className="size-full object-cover object-top"
                    />
                ) : blob.isError ? (
                    <span className="flex size-full items-center justify-center text-muted-foreground">
                        <ImageOff className="size-5" />
                    </span>
                ) : (
                    <span className="block size-full animate-pulse bg-muted" />
                )}
            </button>
            <figcaption
                className="mt-1 truncate text-[11px] text-muted-foreground"
                title={attachment.name}
            >
                {attachment.name}
                {attachment.width && attachment.height
                    ? ` · ${attachment.width}×${attachment.height}`
                    : ''}
            </figcaption>
        </figure>
    );
}

/** An object URL for a blob, given back when the blob changes or the image goes. */
function useObjectUrl(blob: Blob | null): string | null {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!blob) {
            return;
        }

        const made = URL.createObjectURL(blob);
        // eslint-disable-next-line react-hooks/set-state-in-effect -- syncing with an external resource (an object URL) that must be revoked
        setUrl(made);

        return () => {
            URL.revokeObjectURL(made);
            setUrl(null);
        };
    }, [blob]);

    return url;
}
