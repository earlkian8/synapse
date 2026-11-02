import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { median } from '../lib';

export type PeerGroup = {
    key: string;
    label: string;
    /** Everyone's score in the group, this person's included. */
    scores: number[];
};

/**
 * Where one person sits in groups HR compares people in — everyone in the run,
 * their department, their position: each group's scores as dots on one 0–100
 * scale, its middle marked, the person picked out, and their place in words. A
 * group of one says so instead of drawing a lonely dot.
 */
export function PeerComparison({
    groups,
    value,
    name,
    noun,
    higherFirst = 'highest',
    lowerFirst = 'lowest',
}: {
    groups: PeerGroup[];
    value: number;
    name: string;
    /** What the score measures ("risk", "readiness", "forecast"). */
    noun: string;
    /** How a place is read from each end ("highest", "lowest"). */
    higherFirst?: string;
    lowerFirst?: string;
}) {
    const shown = groups.filter((g) => g.scores.length > 0);

    return (
        <ul className="flex flex-col gap-3">
            {shown.map((group) => {
                const n = group.scores.length;
                const middle = median(group.scores);
                // Read from whichever end the person is nearer.
                const above = group.scores.filter((s) => s > value).length;
                const below = group.scores.filter((s) => s < value).length;
                const fromTop = above <= below;
                const place = (fromTop ? above : below) + 1;
                const end = fromTop ? higherFirst : lowerFirst;

                return (
                    <li
                        key={group.key}
                        className="grid grid-cols-1 gap-1.5 sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)] sm:items-center sm:gap-4"
                    >
                        <div className="flex min-w-0 flex-col">
                            <span className="truncate text-sm">
                                {group.label}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {n === 1
                                    ? 'Only them in this run'
                                    : `${place === 1 ? end : `${ordinal(place)} ${end}`} of ${n} · middle ${Math.round(middle)}`}
                            </span>
                        </div>
                        <div
                            className="relative h-6"
                            role="img"
                            aria-label={`${group.label}: ${n} people, middle ${noun} ${Math.round(middle)}; ${name} ${Math.round(value)}`}
                        >
                            <span className="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-border" />
                            {group.scores.map((score, i) => (
                                <span
                                    key={i}
                                    aria-hidden
                                    className="absolute top-1/2 size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-muted-foreground/45"
                                    style={{ left: `${score}%` }}
                                />
                            ))}
                            {n > 1 && (
                                <span
                                    aria-hidden
                                    className="absolute inset-y-0.5 w-0.5 -translate-x-1/2 rounded-full bg-foreground/45"
                                    style={{ left: `${middle}%` }}
                                />
                            )}
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        tabIndex={0}
                                        className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#0ABFBF] ring-2 ring-card outline-none focus-visible:ring-ring"
                                        style={{ left: `${value}%` }}
                                    />
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p className="font-semibold tabular-nums">
                                        {name}: {Math.round(value)}
                                    </p>
                                    <p className="opacity-80">
                                        Middle of {group.label.toLowerCase()}:{' '}
                                        {Math.round(middle)}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </div>
                    </li>
                );
            })}
            <li className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground sm:pl-[12rem]">
                <span className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-full bg-[#0ABFBF]" />
                    {name}
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="size-1.5 rounded-full bg-muted-foreground/45" />
                    Everyone else
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="h-3 w-0.5 rounded-full bg-foreground/45" />
                    Middle
                </span>
            </li>
        </ul>
    );
}

function ordinal(n: number): string {
    const rest = n % 100;
    const suffix =
        rest >= 11 && rest <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] ?? 'th');

    return `${n}${suffix}`;
}
