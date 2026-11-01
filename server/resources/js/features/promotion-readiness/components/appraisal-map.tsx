import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { formatChange, formatScore, TIER_LABELS } from '../constants';
import { READINESS_GROUPS } from '../explain';
import type { ReadinessReference, ReadinessScore } from '../types';

const W = 560;
const H = 260;
const PAD = { top: 12, right: 14, bottom: 30, left: 40 };
const IW = W - PAD.left - PAD.right;
const IH = H - PAD.top - PAD.bottom;
const STRIP = 26;

const FILL = Object.fromEntries(
    READINESS_GROUPS.map((g) => [g.key, g.svgFill]),
);

/**
 * The model's whole input space, with everyone in the run on it: latest appraisal
 * across, its change on the previous one up, each person coloured by tier. Readiness
 * rises to the upper right, so the chart shows at a glance *why* each person sits
 * where they do. People with one appraisal have no change yet; they sit on a strip
 * below. Each dot opens that person's breakdown.
 */
export function AppraisalMap({
    scores,
    reference,
    onOpen,
}: {
    scores: ReadinessScore[];
    reference: ReadinessReference | null;
    onOpen: (score: ReadinessScore) => void;
}) {
    const two = scores.filter((s) => s.features.rating_change !== undefined);
    const one = scores.filter((s) => s.features.rating_change === undefined);

    const changes = two.map((s) => s.features.rating_change ?? 0);
    const span = Math.max(10, ...changes.map((c) => Math.abs(c)));
    const yLo = -Math.ceil(span / 5) * 5;
    const yHi = -yLo;
    const x = (rating: number) =>
        PAD.left + ((Math.min(100, Math.max(40, rating)) - 40) / 60) * IW;
    const y = (change: number) =>
        PAD.top + IH - ((change - yLo) / (yHi - yLo)) * IH;
    const stripY = H + STRIP / 2;
    const yTicks = [yLo, yLo / 2, 0, yHi / 2, yHi];

    const dot = (s: ReadinessScore, cx: number, cy: number) => (
        <Tooltip key={s.id}>
            <TooltipTrigger asChild>
                <g
                    role="button"
                    tabIndex={0}
                    aria-label={`${s.employee?.full_name ?? 'Employee'}: readiness ${formatScore(s.score)}, ${TIER_LABELS[s.tier]}`}
                    className="cursor-pointer outline-none [&:focus-visible>circle:last-child]:stroke-ring [&:hover>circle:last-child]:brightness-110"
                    onClick={() => onOpen(s)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            onOpen(s);
                        }
                    }}
                >
                    <circle cx={cx} cy={cy} r={12} fill="transparent" />
                    <circle
                        cx={cx}
                        cy={cy}
                        r={4.5}
                        className={cn(FILL[s.tier], 'stroke-card')}
                        strokeWidth={2}
                    />
                </g>
            </TooltipTrigger>
            <TooltipContent side="top">
                <p className="font-semibold">
                    {formatScore(s.score)} · {TIER_LABELS[s.tier]}
                </p>
                <p className="opacity-80">{s.employee?.full_name}</p>
                <p className="opacity-80">
                    {s.features.rating_latest?.toFixed(1)}% latest
                    {s.features.rating_change !== undefined &&
                        `, ${formatChange(s.features.rating_change)}`}
                </p>
            </TooltipContent>
        </Tooltip>
    );

    return (
        <figure className="flex flex-col gap-1">
            <svg
                viewBox={`0 0 ${W} ${H + (one.length > 0 ? STRIP + 8 : 0)}`}
                className="h-auto w-full"
                aria-label="Each person by latest appraisal and its change on the previous one, coloured by readiness tier"
            >
                {yTicks.map((v) => (
                    <g key={v}>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={y(v)}
                            y2={y(v)}
                            className={
                                v === 0
                                    ? 'stroke-foreground/30'
                                    : 'stroke-border'
                            }
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(v) + 3}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {v > 0
                                ? `+${v}`
                                : v === 0
                                  ? '0'
                                  : `−${Math.abs(v)}`}
                        </text>
                    </g>
                ))}
                {[40, 50, 60, 70, 80, 90, 100].map((r) => (
                    <text
                        key={r}
                        x={x(r)}
                        y={PAD.top + IH + 14}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[10px] tabular-nums"
                    >
                        {r}%
                    </text>
                ))}
                <text
                    x={PAD.left + IW / 2}
                    y={PAD.top + IH + 27}
                    textAnchor="middle"
                    className="fill-muted-foreground text-[10px]"
                >
                    Latest appraisal
                </text>
                <text
                    transform={`translate(10 ${PAD.top + IH / 2}) rotate(-90)`}
                    textAnchor="middle"
                    className="fill-muted-foreground text-[10px]"
                >
                    Change on the previous (pts)
                </text>

                {/* The typical record the breakdowns are measured from. */}
                {reference && (
                    <g
                        aria-hidden
                        className="stroke-foreground/60"
                        strokeWidth={1.5}
                    >
                        <line
                            x1={x(reference.typical.rating_latest) - 6}
                            x2={x(reference.typical.rating_latest) + 6}
                            y1={y(reference.typical.rating_change)}
                            y2={y(reference.typical.rating_change)}
                        />
                        <line
                            x1={x(reference.typical.rating_latest)}
                            x2={x(reference.typical.rating_latest)}
                            y1={y(reference.typical.rating_change) - 6}
                            y2={y(reference.typical.rating_change) + 6}
                        />
                    </g>
                )}

                {two.map((s) =>
                    dot(
                        s,
                        x(s.features.rating_latest ?? 40),
                        y(s.features.rating_change ?? 0),
                    ),
                )}

                {one.length > 0 && (
                    <g>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={stripY}
                            y2={stripY}
                            className="stroke-border"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={stripY + 3}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px]"
                        >
                            1 only
                        </text>
                        {one.map((s) =>
                            dot(s, x(s.features.rating_latest ?? 40), stripY),
                        )}
                    </g>
                )}
            </svg>
            <figcaption className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {[...READINESS_GROUPS].reverse().map((g) => (
                    <span key={g.key} className="flex items-center gap-1.5">
                        <span className={cn('size-2.5 rounded-full', g.fill)} />
                        {g.label}
                    </span>
                ))}
                {reference && (
                    <span className="flex items-center gap-1.5">
                        <span className="text-sm leading-none text-foreground/60">
                            +
                        </span>
                        Typical record
                    </span>
                )}
                {one.length > 0 && (
                    <span>“1 only”: one appraisal so far, no change yet</span>
                )}
            </figcaption>
        </figure>
    );
}
