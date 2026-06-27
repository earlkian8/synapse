import { useMemo } from 'react';
import { ratingStroke } from '../constants';
import type { ForecastHistoryPoint } from '../types';

const VIEW_W = 320;
const VIEW_H = 132;
const PAD = { top: 14, right: 16, bottom: 26, left: 16 };
const INNER_W = VIEW_W - PAD.left - PAD.right;
const INNER_H = VIEW_H - PAD.top - PAD.bottom;

type Point = { x: number; y: number; rating: number; label: string | null };

/**
 * An employee's rating trajectory: past actual ratings (solid line + dots) ending
 * in the model's predicted next-period rating (a dashed connector to a ringed
 * point). Pure inline SVG — no chart dependency, matching the rest of the app.
 */
export function TrajectoryChart({
    history,
    forecast,
}: {
    history: ForecastHistoryPoint[];
    forecast: number;
}) {
    const { actuals, predicted, domain } = useMemo(() => {
        const values = [...history.map((h) => h.rating), forecast];
        let lo = Math.min(...values);
        let hi = Math.max(...values);

        if (lo === hi) {
            lo -= 8;
            hi += 8;
        }

        const pad = Math.max(5, (hi - lo) * 0.18);
        const domainLo = Math.max(0, lo - pad);
        const domainHi = Math.min(100, hi + pad);

        const total = history.length + 1; // history points + the forecast
        const x = (i: number): number =>
            total === 1
                ? PAD.left + INNER_W / 2
                : PAD.left + (i / (total - 1)) * INNER_W;
        const y = (v: number): number =>
            PAD.top +
            INNER_H -
            ((v - domainLo) / (domainHi - domainLo || 1)) * INNER_H;

        const actuals: Point[] = history.map((h, i) => ({
            x: x(i),
            y: y(h.rating),
            rating: h.rating,
            label: h.label,
        }));

        const predicted: Point = {
            x: x(total - 1),
            y: y(forecast),
            rating: forecast,
            label: 'Forecast',
        };

        return { actuals, predicted, domain: { lo: domainLo, hi: domainHi } };
    }, [history, forecast]);

    const stroke = ratingStroke(forecast);
    const actualPath = actuals
        .map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`)
        .join(' ');
    const last = actuals.at(-1);

    return (
        <figure className="flex flex-col gap-1.5">
            <svg
                viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
                className="h-36 w-full"
                role="img"
                aria-label="Performance rating trajectory ending in the forecast"
            >
                {/* Domain guide rails (top / bottom of the visible band). */}
                {[domain.hi, domain.lo].map((v, i) => {
                    const yy = PAD.top + (i === 0 ? 0 : INNER_H);

                    return (
                        <g key={v}>
                            <line
                                x1={PAD.left}
                                x2={VIEW_W - PAD.right}
                                y1={yy}
                                y2={yy}
                                className="stroke-border"
                                strokeWidth={1}
                                strokeDasharray="2 3"
                            />
                            <text
                                x={PAD.left}
                                y={yy - 3}
                                className="fill-muted-foreground text-[9px]"
                            >
                                {Math.round(v)}
                            </text>
                        </g>
                    );
                })}

                {/* History line + dots. */}
                {actuals.length > 1 && (
                    <path
                        d={actualPath}
                        fill="none"
                        className="stroke-muted-foreground/50"
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                )}
                {actuals.map((p, i) => (
                    <circle
                        key={i}
                        cx={p.x}
                        cy={p.y}
                        r={3}
                        className="fill-muted-foreground"
                    >
                        <title>
                            {p.label ? `${p.label}: ` : ''}
                            {Math.round(p.rating)}
                        </title>
                    </circle>
                ))}

                {/* Dashed connector from the last actual to the forecast. */}
                {last && (
                    <line
                        x1={last.x}
                        y1={last.y}
                        x2={predicted.x}
                        y2={predicted.y}
                        stroke={stroke}
                        strokeWidth={2}
                        strokeDasharray="4 3"
                        strokeLinecap="round"
                    />
                )}

                {/* The forecast point: a filled dot ringed for emphasis. */}
                <circle
                    cx={predicted.x}
                    cy={predicted.y}
                    r={6}
                    fill={stroke}
                    fillOpacity={0.18}
                />
                <circle cx={predicted.x} cy={predicted.y} r={3.5} fill={stroke}>
                    <title>Forecast: {Math.round(predicted.rating)}</title>
                </circle>
                <text
                    x={predicted.x}
                    y={predicted.y - 11}
                    textAnchor="middle"
                    className="text-[10px] font-semibold"
                    fill={stroke}
                >
                    {Math.round(predicted.rating)}
                </text>
            </svg>

            <figcaption className="flex items-center justify-center gap-4 text-[11px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <span className="size-1.5 rounded-full bg-muted-foreground" />
                    Actual
                </span>
                <span className="flex items-center gap-1.5">
                    <span
                        className="size-1.5 rounded-full"
                        style={{ background: stroke }}
                    />
                    Forecast
                </span>
            </figcaption>
        </figure>
    );
}
