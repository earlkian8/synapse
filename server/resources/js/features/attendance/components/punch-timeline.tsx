import { MapPin, StickyNote } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatTime, PUNCH_META } from '../constants';
import type { Punch } from '../types';

/**
 * A vertical timeline of the day's punches — the audit trail. Each punch shows
 * its time, source, optional GPS coordinates and selfie thumbnail.
 */
export function PunchTimeline({ punches }: { punches: Punch[] }) {
    if (punches.length === 0) {
        return (
            <p className="rounded-lg bg-muted/50 px-3 py-6 text-center text-sm text-muted-foreground">
                No punches recorded for this day.
            </p>
        );
    }

    return (
        <ol className="relative space-y-4 pl-6">
            {/* The connecting rail. */}
            <span className="absolute top-1 bottom-1 left-[11px] w-px bg-border" />

            {punches.map((punch) => {
                const meta = PUNCH_META[punch.type];
                const Icon = meta.icon;
                const hasGeo =
                    punch.latitude !== null && punch.longitude !== null;

                return (
                    <li key={punch.id} className="relative">
                        <span
                            className={cn(
                                'absolute top-0.5 -left-6 flex size-6 items-center justify-center rounded-full ring-4 ring-card',
                                meta.accent,
                            )}
                        >
                            <Icon className="size-3" />
                        </span>

                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    {meta.label}
                                    <span className="ml-2 text-xs font-normal text-muted-foreground tabular-nums">
                                        {formatTime(punch.punched_at)}
                                    </span>
                                </p>
                                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-muted-foreground">
                                    <span className="rounded bg-muted px-1.5 py-0.5 capitalize">
                                        {punch.source}
                                    </span>
                                    {hasGeo && (
                                        <a
                                            href={`https://www.google.com/maps?q=${punch.latitude},${punch.longitude}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 hover:text-foreground"
                                        >
                                            <MapPin className="size-3" />
                                            {punch.latitude?.toFixed(4)},{' '}
                                            {punch.longitude?.toFixed(4)}
                                        </a>
                                    )}
                                    {punch.note && (
                                        <span className="inline-flex items-center gap-1">
                                            <StickyNote className="size-3" />
                                            {punch.note}
                                        </span>
                                    )}
                                </div>
                                {punch.recorder && (
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        Recorded by {punch.recorder}
                                    </p>
                                )}
                            </div>

                            {punch.photo && (
                                <a
                                    href={punch.photo}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="shrink-0"
                                >
                                    <img
                                        src={punch.photo}
                                        alt="Punch selfie"
                                        className="size-10 rounded-lg object-cover ring-1 ring-border"
                                    />
                                </a>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
