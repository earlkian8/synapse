import { formatShare } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import { BAND_LABELS } from '../constants';
import { BAND_GROUPS } from '../explain';
import type { ForecastBand } from '../types';

/**
 * Where the next rating could land, as the chance of each band: one bar split
 * three ways (a surface gap between segments), each segment named with its share,
 * the forecast's own band in bold.
 */
export function BandChances({
    chances,
    band,
}: {
    chances: Record<ForecastBand, number>;
    band: ForecastBand;
}) {
    return (
        <figure
            className="flex flex-col gap-2"
            aria-label={BAND_GROUPS.map(
                (g) => `${g.label} ${formatShare(chances[g.key])}`,
            ).join(', ')}
        >
            <div className="flex h-3 gap-[2px]">
                {BAND_GROUPS.map((g) =>
                    chances[g.key] > 0 ? (
                        <div
                            key={g.key}
                            className={cn(
                                'h-full first:rounded-l-[4px] last:rounded-r-[4px]',
                                g.fill,
                            )}
                            style={{ flexGrow: chances[g.key] }}
                        />
                    ) : null,
                )}
            </div>
            <figcaption className="grid grid-cols-3 gap-2 text-xs">
                {BAND_GROUPS.map((g) => (
                    <span
                        key={g.key}
                        className={cn(
                            'flex items-center gap-1.5 text-muted-foreground',
                            g.key === 'on_track' && 'justify-center',
                            g.key === 'exceeds' && 'justify-end',
                        )}
                    >
                        <span className={cn('size-2 rounded-[2px]', g.fill)} />
                        {BAND_LABELS[g.key]}
                        <span
                            className={cn(
                                'tabular-nums',
                                g.key === band
                                    ? 'font-semibold text-foreground'
                                    : 'font-medium',
                            )}
                        >
                            {formatShare(chances[g.key])}
                        </span>
                    </span>
                ))}
            </figcaption>
        </figure>
    );
}
