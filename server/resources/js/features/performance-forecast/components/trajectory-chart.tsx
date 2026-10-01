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
 * point) with the range four in five next ratings land in (a shaded whisker), and
 * — once the period is appraised — where the actual rating landed. Pure inline
 * SVG — no chart dependency, matching the rest of the app.
 */
export function TrajectoryChart({
    history,
    forecast,
    range,
    actual,
}: {
    history: ForecastHistoryPoint[];
    forecast: number;
    range?: { low: number; high: number } | null;
    actual?: number | null;
}) {
    const { actuals, predicted, domain, band, outcome } = useMemo(() => {
        const values = [
            ...history.map((h) => h.rating),
            forecast,
            ...(range ? [range.low, range.high] : []),
            ...(actual != null ? [actual] : []),
        ];
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

        const band = range
            ? { top: y(range.high), bottom: y(range.low) }
            : null;
        const outcome =
            actual != null ? { x: predicted.x, y: y(actual) } : null;

        return {
            actuals,
            predicted,
            domain: { lo: domainLo, hi: domainHi },
            band,
            outcome,
        };
    }, [history, forecast, range, actual]);

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

                {/* The range four in five next ratings land in. */}
                {band && range && (
                    <g>
                        <rect
                            x={predicted.x - 7}
                            y={band.top}
                            width={14}
                            height={Math.max(1, band.bottom - band.top)}
                            rx={3}
                            fill={stroke}
                            fillOpacity={0.14}
                        >
                            <title>
                                Likely range: {Math.round(range.low)}–
                                {Math.round(range.high)}
                            </title>
                        </rect>
                        {[band.top, band.bottom].map((yy) => (
                            <line
                                key={yy}
                                x1={predicted.x - 7}
                                x2={predicted.x + 7}
                                y1={yy}
                                y2={yy}
                                stroke={stroke}
                                strokeOpacity={0.6}
                                strokeWidth={1.5}
                            />
                        ))}
                    </g>
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
                    x={predicted.x - 11}
                    y={predicted.y + 3}
                    textAnchor="end"
                    className="text-[10px] font-semibold"
                    fill={stroke}
                >
                    {Math.round(predicted.rating)}
                </text>

                {/* Where the actual rating landed, once the period is appraised. */}
                {outcome && (
                    <path
                        d={`M ${outcome.x - 4} ${outcome.y - 4} L ${outcome.x + 4} ${outcome.y + 4} M ${outcome.x - 4} ${outcome.y + 4} L ${outcome.x + 4} ${outcome.y - 4}`}
                        className="stroke-foreground"
                        strokeWidth={1.75}
                        strokeLinecap="round"
                    >
                        <title>Actual: {Math.round(actual ?? 0)}</title>
                    </path>
                )}
            </svg>

            <figcaption className="flex items-center justify-center gap-4 text-[11px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <span className="size-1.5 rounded-full bg-muted-foreground" />
                    Past appraisals
                </span>
                <span className="flex items-center gap-1.5">
                    <span
                        className="size-1.5 rounded-full"
                        style={{ background: stroke }}
                    />
                    Forecast
                </span>
                {range && (
                    <span className="flex items-center gap-1.5">
                        <span
                            className="h-2.5 w-1.5 rounded-sm"
                            style={{ background: stroke, opacity: 0.25 }}
                        />
                        Likely range (4 in 5)
                    </span>
                )}
                {actual != null && (
                    <span className="flex items-center gap-1.5">
                        <span className="text-[10px] leading-none font-semibold text-foreground">
                            ×
                        </span>
                        Actual result
                    </span>
                )}
            </figcaption>
        </figure>
    );
}
