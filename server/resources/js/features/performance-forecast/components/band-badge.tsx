import { cn } from '@/lib/utils';
import { BAND_LABELS, BAND_STYLES } from '../constants';
import type { ForecastBand } from '../types';

const BASE =
    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** A pill for an employee's forecast band. */
export function BandBadge({
    band,
    className,
}: {
    band: ForecastBand;
    className?: string;
}) {
    return (
        <span className={cn(BASE, BAND_STYLES[band], className)}>
            <span className="size-1.5 rounded-full bg-current opacity-80" />
            {BAND_LABELS[band]}
        </span>
    );
}
