import { cn } from '@/lib/utils';
import { bandFor, bandTone, orderedBands } from '../constants';
import type { RatingBand } from '../types';

type Props = {
    /** The tenant's rating model — the bands this result is reported in. */
    bands: RatingBand[];
    /** Attainment on 0–100, or null while the appraisal is unscored. */
    percent: number | null;
    /** `rail` is the bare track; `full` adds the band labels and cut points. */
    variant?: 'rail' | 'full';
    className?: string;
};

/**
 * The rating ladder — this module's one picture, and the answer to "what does a
 * performance score even mean here".
 *
 * It draws the **company's own rating model** as the 0–100 track it really is:
 * one segment per band, each as wide as the span it owns, labelled in that
 * company's words, with the result standing on the segment it reached. A tenant
 * running five bands and a tenant running "A / B / C / D" both read their own
 * model back, not someone else's five stars.
 *
 * The same drawing carries the distribution on the overview, where the segments
 * are sized by how many people landed in each band instead.
 */
export function RatingLadder({
    bands,
    percent,
    variant = 'full',
    className,
}: Props) {
    const ordered = orderedBands(bands);
    const achieved = bandFor(percent, bands);

    if (ordered.length === 0) {
        return null;
    }

    // Drawn low-to-high, so the ladder reads left-to-right like the scale does.
    const ascending = [...ordered].reverse();

    const segments = ascending.map((band, index) => {
        const next = ascending[index + 1];
        const to = next ? next.min_percent : 100;

        return {
            band,
            from: band.min_percent,
            width: Math.max(0, to - band.min_percent),
        };
    });

    const label = achieved
        ? `${percent?.toFixed(1)}% — ${achieved.label}`
        : 'Not yet rated';

    return (
        <div className={cn('w-full', className)}>
            <div
                className="relative"
                role="img"
                aria-label={`Rating ladder. ${label}.`}
            >
                <div
                    className={cn(
                        'flex w-full overflow-hidden rounded-full bg-muted',
                        variant === 'full' ? 'h-2.5' : 'h-1.5',
                    )}
                >
                    {segments.map(({ band, width }) => {
                        const tone = bandTone(band.tone);
                        const isAchieved = achieved?.key === band.key;

                        return (
                            <div
                                key={band.key}
                                style={{ width: `${width}%` }}
                                className={cn(
                                    'h-full border-r border-background/70 transition-colors last:border-r-0',
                                    isAchieved
                                        ? tone.fill
                                        : percent === null
                                          ? tone.soft
                                          : 'bg-muted-foreground/12',
                                )}
                            />
                        );
                    })}
                </div>

                {/* Where the result actually landed. */}
                {percent !== null && (
                    <span
                        className="pointer-events-none absolute -top-1 bottom-[-0.25rem] w-0.5 -translate-x-1/2 rounded-full bg-foreground shadow-sm"
                        style={{
                            left: `${Math.min(100, Math.max(0, percent))}%`,
                        }}
                    />
                )}
            </div>

            {variant === 'full' && (
                <div className="mt-2 flex w-full">
                    {segments.map(({ band, from, width }) => {
                        const tone = bandTone(band.tone);
                        const isAchieved = achieved?.key === band.key;

                        return (
                            <div
                                key={band.key}
                                style={{ width: `${width}%` }}
                                className="min-w-0 pr-2 last:pr-0"
                            >
                                <p
                                    className={cn(
                                        'truncate text-[11px] leading-tight font-medium',
                                        isAchieved
                                            ? tone.text
                                            : 'text-muted-foreground',
                                    )}
                                    title={band.label}
                                >
                                    {band.label}
                                </p>
                                <p className="text-[10px] text-muted-foreground/70 tabular-nums">
                                    {from}%+
                                </p>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
