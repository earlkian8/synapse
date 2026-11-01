import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { formatPoints, POLARITY } from '../lib';

export type DriverRow = {
    key: string;
    label: string;
    /** People whose score this input pushes up / down (by at least the model's floor). */
    up: number;
    down: number;
    /** The average points among those it pushes up / down. */
    upPoints: number;
    downPoints: number;
};

/**
 * What drives a run, across everyone in it: for each input, how many people it
 * pushes up and how many it pulls down, as a diverging bar — down to the left, up to
 * the right of one shared axis. Sorted by how many people the input moves at all.
 */
export function DriverChart({
    rows,
    upFavourable,
    upLabel,
    downLabel,
}: {
    rows: DriverRow[];
    /** Whether a push up is good news (readiness) or bad (risk). */
    upFavourable: boolean;
    upLabel: string;
    downLabel: string;
}) {
    const max = Math.max(1, ...rows.flatMap((r) => [r.up, r.down]));
    const upFill = upFavourable
        ? POLARITY.favourable.fill
        : POLARITY.unfavourable.fill;
    const downFill = upFavourable
        ? POLARITY.unfavourable.fill
        : POLARITY.favourable.fill;

    return (
        <div className="flex flex-col gap-1">
            <div className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_minmax(0,1fr)] gap-x-2 text-[11px] text-muted-foreground">
                <span />
                <span className="flex items-center justify-end gap-1.5 pr-1">
                    {downLabel}
                    <span className={cn('size-2 rounded-[2px]', downFill)} />
                </span>
                <span className="flex items-center gap-1.5 pl-1">
                    <span className={cn('size-2 rounded-[2px]', upFill)} />
                    {upLabel}
                </span>
            </div>
            <ul className="flex flex-col">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-x-2 py-1"
                    >
                        <span className="truncate text-xs">{row.label}</span>
                        <Half
                            count={row.down}
                            points={row.downPoints}
                            width={(row.down / max) * 100}
                            fill={downFill}
                            side="down"
                            label={downLabel}
                            input={row.label}
                        />
                        <Half
                            count={row.up}
                            points={row.upPoints}
                            width={(row.up / max) * 100}
                            fill={upFill}
                            side="up"
                            label={upLabel}
                            input={row.label}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Half({
    count,
    points,
    width,
    fill,
    side,
    label,
    input,
}: {
    count: number;
    points: number;
    width: number;
    fill: string;
    side: 'up' | 'down';
    label: string;
    input: string;
}) {
    const down = side === 'down';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div
                    tabIndex={count > 0 ? 0 : -1}
                    aria-label={`${input}: ${label.toLowerCase()} for ${count}`}
                    className={cn(
                        'group flex h-6 items-center gap-1.5 outline-none focus-visible:bg-muted/60',
                        down
                            ? 'flex-row-reverse border-l-0 pl-0'
                            : 'border-l border-foreground/30 pl-px',
                    )}
                >
                    <span
                        className={cn(
                            'h-3.5 group-hover:brightness-110',
                            down ? 'rounded-l-[4px]' : 'rounded-r-[4px]',
                            count > 0 ? fill : '',
                        )}
                        style={{ width: count > 0 ? `max(2px, ${width}%)` : 0 }}
                    />
                    <span className="text-[11px] text-muted-foreground tabular-nums">
                        {count > 0 ? count : ''}
                    </span>
                </div>
            </TooltipTrigger>
            {count > 0 && (
                <TooltipContent side="top">
                    <p className="font-semibold tabular-nums">
                        {count} {count === 1 ? 'person' : 'people'}
                    </p>
                    <p className="opacity-80">
                        {input} {label.toLowerCase()} · on average{' '}
                        {formatPoints(points)} points
                    </p>
                </TooltipContent>
            )}
        </Tooltip>
    );
}
