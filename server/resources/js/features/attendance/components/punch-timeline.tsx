import { CameraOff, ImageOff, MapPin, StickyNote } from 'lucide-react';
import { useState } from 'react';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import { formatTime, PUNCH_META, SOURCE_LABELS } from '../constants';
import type { Punch } from '../types';

/**
 * The day's punch trail: every punch, and the photo taken with it.
 *
 * The photo is the whole reason the trail is auditable — it is what makes a
 * punch taken away from the office checkable — so it sits **on the row it
 * belongs to**, at a size a face can be recognised in, rather than being
 * repeated in a column beside it.
 *
 * A punch with no photo still gets a tile, saying which of the three things
 * happened: the source never takes one (a web or biometric punch), the mobile
 * app was expected to and did not, or the file has since gone. A blank space
 * says none of that, and "no evidence" and "evidence missing" are not the same
 * finding.
 */
export function PunchTimeline({ punches }: { punches: Punch[] }) {
    if (punches.length === 0) {
        return (
            <p className="rounded-lg border border-dashed border-border px-3 py-5 text-center text-sm text-muted-foreground">
                No punches on this day. Anything recorded by hand will appear
                here too.
            </p>
        );
    }

    return (
        <ol className="divide-y divide-border overflow-hidden rounded-lg border border-border">
            {punches.map((punch) => (
                <PunchRow key={punch.id} punch={punch} />
            ))}
        </ol>
    );
}

/** One punch: what happened and when, where it came from, and the proof. */
function PunchRow({ punch }: { punch: Punch }) {
    const timeZone = useOrganizationTimeZone();
    const meta = PUNCH_META[punch.type];
    const Icon = meta.icon;
    const hasGeo = punch.latitude !== null && punch.longitude !== null;

    return (
        <li className="flex items-center gap-3 px-3 py-2.5">
            <span
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full',
                    meta.accent,
                )}
            >
                <Icon className="size-3.5" />
            </span>

            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">
                    {meta.label}
                    <span className="ml-2 text-xs font-normal text-muted-foreground tabular-nums">
                        {formatTime(punch.punched_at, timeZone)}
                    </span>
                </p>

                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground">
                    <span className="rounded bg-muted px-1.5 py-0.5">
                        {SOURCE_LABELS[punch.source]}
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
                        <span className="inline-flex min-w-0 items-center gap-1">
                            <StickyNote className="size-3 shrink-0" />
                            <span className="truncate">{punch.note}</span>
                        </span>
                    )}
                    {punch.recorder && <span>by {punch.recorder}</span>}
                </div>
            </div>

            <PunchPhoto punch={punch} />
        </li>
    );
}

/**
 * Why a punch has no photo, short enough to sit inside the tile. The prose form
 * ({@link SOURCE_LABELS}) stays on the row itself and in the tile's tooltip.
 */
const NO_PHOTO: Record<Punch['source'], string> = {
    web: 'Web punch',
    mobile: 'Not taken',
    kiosk: 'Kiosk punch',
    biometric: 'Biometric',
    manual: 'By hand',
    correction: 'Corrected',
};

/**
 * The proof, or the reason there is none. Every punch gets a tile so the column
 * stays a column: the trail reads down it as evenly as it reads down the times.
 */
function PunchPhoto({ punch }: { punch: Punch }) {
    const [broken, setBroken] = useState(false);
    const meta = PUNCH_META[punch.type];

    if (punch.photo && !broken) {
        return (
            <a
                href={punch.photo}
                target="_blank"
                rel="noreferrer"
                title="Open the full-size photo"
                className="shrink-0 overflow-hidden rounded-md ring-1 ring-border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <img
                    src={punch.photo}
                    alt={`Photo taken at ${meta.label.toLowerCase()}`}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="size-16 bg-muted object-cover transition-opacity hover:opacity-90"
                />
            </a>
        );
    }

    const state = broken
        ? { icon: ImageOff, title: 'Photo gone', note: 'File missing' }
        : { icon: CameraOff, title: 'No photo', note: NO_PHOTO[punch.source] };

    const Icon = state.icon;

    return (
        <div
            className="flex size-16 shrink-0 flex-col items-center justify-center gap-0.5 rounded-md border border-dashed border-border bg-muted/40 px-1 text-center text-muted-foreground"
            title={
                broken
                    ? 'This photo is no longer available'
                    : `No photo was taken with this punch (${SOURCE_LABELS[punch.source]})`
            }
        >
            <Icon className="size-4" />
            <span className="text-[10px] leading-tight font-medium">
                {state.title}
            </span>
            <span className="text-[9px] leading-tight">{state.note}</span>
        </div>
    );
}
