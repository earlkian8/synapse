import { useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { cn } from '@/lib/utils';
import { TIER_LABELS } from '../constants';
import type { ReadinessOutlook, ReadinessTier } from '../types';

const W = 560;
const H = 210;
const PAD = { top: 12, right: 14, bottom: 30, left: 34 };
const IW = W - PAD.left - PAD.right;
const IH = H - PAD.top - PAD.bottom;

/**
 * Readiness after the next appraisal, for every rating it could give: the next
 * rating becomes the latest and its change on today's latest the change, read by
 * the model exactly as it will be then. The tiers are shaded bands, today's latest
 * rating is marked ("the same again"), and where the curve enters each tier is
 * labelled. A crosshair reads any rating on hover or with the arrow keys.
 */
export function NextAppraisalChart({
    outlook,
    latest,
    tierScores,
    tierOf,
}: {
    outlook: ReadinessOutlook;
    /** Today's latest appraisal (attainment, %). */
    latest: number;
    tierScores: { medium: number; high: number } | null;
    tierOf: (score: number) => ReadinessTier | null;
}) {
    const points = outlook.points;
    const lo = points[0]?.rating ?? 40;
    const hi = points.at(-1)?.rating ?? 100;
    const x = (rating: number) =>
        PAD.left + ((rating - lo) / (hi - lo || 1)) * IW;
    const y = (score: number) => PAD.top + IH - (score / 100) * IH;
    const [active, setActive] = useState<number | null>(null);

    const path = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${x(p.rating).toFixed(1)} ${y(p.score).toFixed(1)}`,
        )
        .join(' ');

    const nearest = (clientX: number, rect: DOMRect) => {
        const rating =
            lo +
            ((((clientX - rect.left) / rect.width) * W - PAD.left) / IW) *
                (hi - lo);
        let best = 0;

        points.forEach((p, i) => {
            if (
                Math.abs(p.rating - rating) <
                Math.abs(points[best].rating - rating)
            ) {
                best = i;
            }
        });

        return best;
    };

    const onMove = (event: PointerEvent<SVGSVGElement>) =>
        setActive(
            nearest(event.clientX, event.currentTarget.getBoundingClientRect()),
        );

    const onKey = (event: KeyboardEvent<SVGSVGElement>) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        event.preventDefault();
        const start = active ?? points.findIndex((p) => p.rating >= latest);
        const step = event.key === 'ArrowLeft' ? -1 : 1;
        setActive(Math.min(points.length - 1, Math.max(0, start + step)));
    };

    const point = active !== null ? points[active] : null;
    const pointTier = point ? tierOf(point.score) : null;
    const zones = tierScores
        ? [
              {
                  key: 'high',
                  from: tierScores.high,
                  to: 100,
                  fill: 'fill-emerald-500/10',
              },
              {
                  key: 'medium',
                  from: tierScores.medium,
                  to: tierScores.high,
                  fill: 'fill-amber-500/10',
              },
          ]
        : [];
    const crossings = (
        [
            ['medium', outlook.medium_from, tierScores?.medium],
            ['high', outlook.high_from, tierScores?.high],
        ] as const
    ).filter(
        (c): c is readonly ['medium' | 'high', number, number] =>
            c[1] !== null && c[2] !== undefined && c[1] > lo,
    );

    return (
        <figure className="relative flex flex-col gap-1">
            <svg
                viewBox={`0 0 ${W} ${H}`}
                className="h-auto w-full touch-none outline-none focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-ring"
                role="img"
                tabIndex={0}
                aria-label="Readiness after the next appraisal, by the rating it gives. Use the left and right arrow keys to read a rating."
                onPointerMove={onMove}
                onPointerLeave={() => setActive(null)}
                onKeyDown={onKey}
                onBlur={() => setActive(null)}
            >
                {/* Tier bands. */}
                {zones.map((z) => (
                    <g key={z.key}>
                        <rect
                            x={PAD.left}
                            width={IW}
                            y={y(z.to)}
                            height={y(z.from) - y(z.to)}
                            className={z.fill}
                        />
                        <text
                            x={W - PAD.right - 4}
                            y={y(z.to) + 12}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px]"
                        >
                            {TIER_LABELS[z.key as ReadinessTier]}
                        </text>
                    </g>
                ))}

                {/* Recessive grid and axes. */}
                {[0, 50, 100].map((v) => (
                    <g key={v}>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={y(v)}
                            y2={y(v)}
                            className="stroke-border"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(v) + 3}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {v}
                        </text>
                    </g>
                ))}
                {Array.from(
                    { length: Math.floor((hi - lo) / 10) + 1 },
                    (_, i) => lo + i * 10,
                ).map((r) => (
                    <text
                        key={r}
                        x={x(r)}
                        y={H - PAD.bottom + 14}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[10px] tabular-nums"
                    >
                        {r}%
                    </text>
                ))}
                <text
                    x={PAD.left + IW / 2}
                    y={H - 3}
                    textAnchor="middle"
                    className="fill-muted-foreground text-[10px]"
                >
                    Next appraisal
                </text>

                {/* Today's latest rating: "the same again". */}
                <line
                    x1={x(latest)}
                    x2={x(latest)}
                    y1={PAD.top}
                    y2={PAD.top + IH}
                    className="stroke-foreground/30"
                    strokeWidth={1}
                />

                <path
                    d={path}
                    fill="none"
                    className="stroke-teal-600"
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                />

                {/* Where the curve enters each tier. */}
                {crossings.map(([tier, rating, score]) => (
                    <g key={tier}>
                        <circle
                            cx={x(rating)}
                            cy={y(score)}
                            r={4}
                            className="fill-teal-600 stroke-card"
                            strokeWidth={2}
                        />
                        <text
                            x={x(rating) - 7}
                            y={y(score) - 7}
                            textAnchor="end"
                            className="fill-foreground text-[10px] font-medium"
                        >
                            {TIER_LABELS[tier]} from {rating}%
                        </text>
                    </g>
                ))}

                {outlook.same_again !== null && (
                    <g>
                        <circle
                            cx={x(latest)}
                            cy={y(outlook.same_again)}
                            r={4.5}
                            className="fill-foreground stroke-card"
                            strokeWidth={2}
                        />
                        <text
                            x={x(latest) + 8}
                            y={y(outlook.same_again) + 14}
                            className="fill-foreground text-[10px] font-medium"
                        >
                            Same again: {Math.round(outlook.same_again)}
                        </text>
                    </g>
                )}

                {/* Crosshair. */}
                {point && (
                    <g pointerEvents="none">
                        <line
                            x1={x(point.rating)}
                            x2={x(point.rating)}
                            y1={PAD.top}
                            y2={PAD.top + IH}
                            className="stroke-foreground/50"
                            strokeWidth={1}
                        />
                        <circle
                            cx={x(point.rating)}
                            cy={y(point.score)}
                            r={4}
                            className="fill-teal-600 stroke-card"
                            strokeWidth={2}
                        />
                    </g>
                )}
            </svg>

            {point && (
                <div
                    className={cn(
                        'pointer-events-none absolute top-1 rounded-md bg-primary px-2.5 py-1.5 text-xs text-primary-foreground shadow-sm',
                        x(point.rating) / W > 0.6 ? '-translate-x-full' : '',
                    )}
                    style={{ left: `${(x(point.rating) / W) * 100}%` }}
                    role="status"
                >
                    <p className="font-semibold tabular-nums">
                        Readiness {Math.round(point.score)}
                        {pointTier ? ` · ${TIER_LABELS[pointTier]}` : ''}
                    </p>
                    <p className="opacity-80">
                        if the next appraisal is {point.rating}%
                    </p>
                </div>
            )}
        </figure>
    );
}
