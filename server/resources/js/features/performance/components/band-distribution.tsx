import { ChartNoAxesColumn } from 'lucide-react';
import { cn } from '@/lib/utils';
import { bandTone } from '../constants';
import type { DistributionBand } from '../types';

type Props = {
    distribution: DistributionBand[];
    total: number;
};

/**
 * How this company rated itself, in its own bands.
 *
 * The same ladder the scorecard draws, but the segments are sized by how many
 * people landed in each band rather than by the band's span. Rating inflation is
 * invisible one appraisal at a time and unmissable here: if four fifths of a
 * company sits in the top band, that is the picture, and the note under the bar
 * says so plainly rather than editorialising.
 */
export function BandDistribution({ distribution, total }: Props) {
    if (distribution.length === 0) {
        return (
            <section className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 p-5 dark:border-sidebar-border">
                <Header />
                <p className="mt-3 text-sm text-muted-foreground">
                    Nothing to calibrate yet. The spread appears once appraisals
                    in this cycle are submitted.
                </p>
            </section>
        );
    }

    const top = distribution[0];

    return (
        <section className="rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <Header />
                <span className="text-xs text-muted-foreground tabular-nums">
                    {total} completed
                </span>
            </div>

            <div
                className="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`Result spread: ${distribution
                    .map((band) => `${band.label} ${band.share}%`)
                    .join(', ')}`}
            >
                {distribution.map((band) => (
                    <div
                        key={band.label}
                        style={{ width: `${band.share}%` }}
                        className={cn(
                            'h-full border-r border-background/70 last:border-r-0',
                            bandTone(band.tone).fill,
                        )}
                    />
                ))}
            </div>

            <ul className="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
                {distribution.map((band) => {
                    const tone = bandTone(band.tone);

                    return (
                        <li
                            key={band.label}
                            className="flex items-center gap-2.5 text-sm"
                        >
                            <span
                                className={cn(
                                    'size-2.5 shrink-0 rounded-sm',
                                    tone.fill,
                                )}
                                aria-hidden="true"
                            />
                            <span className="min-w-0 flex-1 truncate">
                                {band.label}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                {band.count} · {band.share}%
                            </span>
                        </li>
                    );
                })}
            </ul>

            {top.share >= 50 && (
                <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                    {top.share}% of completed appraisals sit in the top band
                    (&ldquo;{top.label}&rdquo;). Worth a calibration
                    conversation before sign-off.
                </p>
            )}
        </section>
    );
}

function Header() {
    return (
        <div className="flex items-center gap-2.5">
            <span className="flex size-7 items-center justify-center rounded-lg bg-[#0F2044]/8 text-[#0F2044] dark:bg-white/10 dark:text-white">
                <ChartNoAxesColumn className="size-4" />
            </span>
            <div>
                <h2 className="text-sm font-semibold">Result spread</h2>
                <p className="text-xs text-muted-foreground">
                    Where this cycle landed across your rating bands
                </p>
            </div>
        </div>
    );
}
