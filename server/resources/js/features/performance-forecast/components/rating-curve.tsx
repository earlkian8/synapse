import { useId } from 'react';
import { BAND_LABELS, ratingStroke } from '../constants';
import type { ForecastReference } from '../types';

const W = 560;
const H = 220;
const PAD = { top: 12, right: 14, bottom: 30, left: 34 };
const IW = W - PAD.left - PAD.right;
const IH = H - PAD.top - PAD.bottom;

/**
 * What any forecast sits on: how the model reads a latest appraisal across its whole
 * range — the next rating it expects (line) and the range four in five next ratings
 * land in (a wash) — against "no change" (the diagonal), with this employee marked.
 * Above the diagonal, ratings tend to rise; below it, to fall back.
 */
export function RatingCurve({
    reference,
    latest,
    forecast,
}: {
    reference: ForecastReference;
    latest: number;
    forecast: number;
}) {
    const clipId = useId();
    const curve = reference.curve;
    const lo = Math.min(curve[0]?.rating ?? 40, 40);
    const hi = 100;
    const yLo = Math.max(0, Math.min(lo, ...curve.map((p) => p.low)) - 5);
    const x = (r: number) => PAD.left + ((r - lo) / (hi - lo)) * IW;
    const y = (v: number) => PAD.top + IH - ((v - yLo) / (hi - yLo)) * IH;

    const line = curve
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${x(p.rating).toFixed(1)} ${y(p.forecast).toFixed(1)}`,
        )
        .join(' ');
    const band = [
        ...curve.map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${x(p.rating).toFixed(1)} ${y(p.high).toFixed(1)}`,
        ),
        ...[...curve]
            .reverse()
            .map((p) => `L ${x(p.rating).toFixed(1)} ${y(p.low).toFixed(1)}`),
        'Z',
    ].join(' ');
    const stroke = ratingStroke(forecast);
    const ticks = [40, 50, 60, 70, 80, 90, 100].filter((t) => t >= lo);

    return (
        <figure className="flex flex-col gap-1">
            <svg
                viewBox={`0 0 ${W} ${H}`}
                className="h-auto w-full"
                role="img"
                aria-label={`How the model reads a latest appraisal: a latest rating of ${latest.toFixed(1)} is followed by about ${Math.round(forecast)}.`}
            >
                <defs>
                    <clipPath id={clipId}>
                        <rect x={PAD.left} y={PAD.top} width={IW} height={IH} />
                    </clipPath>
                </defs>

                {/* Where the bands begin, on the next rating. */}
                {(
                    [
                        [reference.bands.below, BAND_LABELS.on_track],
                        [reference.bands.on_track, BAND_LABELS.exceeds],
                    ] as const
                ).map(([v, label]) => (
                    <g key={label}>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={y(v)}
                            y2={y(v)}
                            className="stroke-border"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left + 4}
                            y={y(v) - 4}
                            className="fill-muted-foreground text-[10px]"
                        >
                            {label} from {v}
                        </text>
                    </g>
                ))}

                {ticks.map((t) => (
                    <g key={t}>
                        <text
                            x={x(t)}
                            y={PAD.top + IH + 14}
                            textAnchor="middle"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {t}
                        </text>
                        {t >= yLo && (
                            <text
                                x={PAD.left - 6}
                                y={y(t) + 3}
                                textAnchor="end"
                                className="fill-muted-foreground text-[10px] tabular-nums"
                            >
                                {t}
                            </text>
                        )}
                    </g>
                ))}
                <text
                    x={PAD.left + IW / 2}
                    y={H - 3}
                    textAnchor="middle"
                    className="fill-muted-foreground text-[10px]"
                >
                    Latest appraisal
                </text>

                <g clipPath={`url(#${clipId})`}>
                    {/* No change: the next rating equals the latest. */}
                    <line
                        x1={x(lo)}
                        y1={y(lo)}
                        x2={x(hi)}
                        y2={y(hi)}
                        className="stroke-foreground/25"
                        strokeWidth={1}
                    />
                    <path d={band} fill={stroke} fillOpacity={0.1} />
                    <path
                        d={line}
                        fill="none"
                        stroke={stroke}
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                </g>

                {/* This employee. */}
                <line
                    x1={x(latest)}
                    x2={x(latest)}
                    y1={y(forecast)}
                    y2={PAD.top + IH}
                    className="stroke-foreground/30"
                    strokeWidth={1}
                />
                <circle
                    cx={x(latest)}
                    cy={y(forecast)}
                    r={5}
                    fill={stroke}
                    className="stroke-card"
                    strokeWidth={2}
                />
                <text
                    x={x(latest) + (latest > 80 ? -9 : 9)}
                    y={y(forecast) - 8}
                    textAnchor={latest > 80 ? 'end' : 'start'}
                    className="fill-foreground text-[11px] font-semibold"
                >
                    {latest.toFixed(1)} → {Math.round(forecast)}
                </text>
            </svg>
            <figcaption className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <span
                        className="h-0.5 w-3 rounded-full"
                        style={{ background: stroke }}
                    />
                    Expected next rating
                </span>
                <span className="flex items-center gap-1.5">
                    <span
                        className="h-2.5 w-3 rounded-sm"
                        style={{ background: stroke, opacity: 0.2 }}
                    />
                    Where four in five land
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="h-px w-3 bg-foreground/40" />
                    No change
                </span>
            </figcaption>
        </figure>
    );
}
