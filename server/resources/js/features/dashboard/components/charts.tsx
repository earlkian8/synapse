import { useEffect, useId, useState } from 'react';
import { cn } from '@/lib/utils';

/** Whether the visitor prefers reduced motion (read once, at module scope). */
const reduceMotion =
    typeof window !== 'undefined' &&
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** Drives the one-shot draw-in animation: false on first paint, true just after. */
function useDrawn(): boolean {
    const [drawn, setDrawn] = useState(reduceMotion);

    useEffect(() => {
        if (reduceMotion) {
            return;
        }

        const id = requestAnimationFrame(() => setDrawn(true));

        return () => cancelAnimationFrame(id);
    }, []);

    return drawn;
}

export type Segment = { label: string; value: number; color: string };

/**
 * A donut of proportional segments around a hollow centre, drawn as rotated arcs of
 * a single circle (no chart dependency). Segments grow in on mount. The centre holds
 * a headline value + caption.
 */
export function Donut({
    segments,
    size = 150,
    thickness = 16,
    centerValue,
    centerLabel,
}: {
    segments: Segment[];
    size?: number;
    thickness?: number;
    centerValue: string;
    centerLabel: string;
}) {
    const drawn = useDrawn();
    const r = (size - thickness) / 2;
    const c = 2 * Math.PI * r;
    const total = segments.reduce((sum, s) => sum + s.value, 0) || 1;
    const center = size / 2;

    // Each arc's start angle is the sum of the fractions before it — derived purely
    // (no running mutation during render, which the react-compiler forbids).
    const fractions = segments.map((seg) => seg.value / total);
    const arcs = segments.map((seg, i) => ({
        seg,
        len: drawn ? fractions[i] * c : 0,
        rotation: fractions.slice(0, i).reduce((sum, f) => sum + f, 0) * 360,
    }));

    return (
        <div className="relative" style={{ width: size, height: size }}>
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                role="img"
            >
                <circle
                    cx={center}
                    cy={center}
                    r={r}
                    fill="none"
                    strokeWidth={thickness}
                    className="stroke-muted"
                />
                <g transform={`rotate(-90 ${center} ${center})`}>
                    {arcs.map(({ seg, len, rotation }) => {
                        if (seg.value === 0) {
                            return null;
                        }

                        return (
                            <circle
                                key={seg.label}
                                cx={center}
                                cy={center}
                                r={r}
                                fill="none"
                                stroke={seg.color}
                                strokeWidth={thickness}
                                strokeLinecap="round"
                                strokeDasharray={`${len} ${c - len}`}
                                transform={`rotate(${rotation} ${center} ${center})`}
                                style={{
                                    transition: reduceMotion
                                        ? undefined
                                        : 'stroke-dasharray 0.9s cubic-bezier(0.22, 1, 0.36, 1)',
                                }}
                            >
                                <title>
                                    {seg.label}: {seg.value}
                                </title>
                            </circle>
                        );
                    })}
                </g>
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-2xl font-semibold tracking-tight tabular-nums">
                    {centerValue}
                </span>
                <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                    {centerLabel}
                </span>
            </div>
        </div>
    );
}

/** A small dot + label + value legend row, to sit beside a {@link Donut}. */
export function Legend({ segments }: { segments: Segment[] }) {
    return (
        <ul className="flex flex-col gap-2">
            {segments.map((seg) => (
                <li key={seg.label} className="flex items-center gap-2 text-sm">
                    <span
                        className="size-2.5 rounded-[3px]"
                        style={{ background: seg.color }}
                    />
                    <span className="flex-1 text-muted-foreground">
                        {seg.label}
                    </span>
                    <span className="font-semibold tabular-nums">
                        {seg.value}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * A filled area trend over a labelled series, normalised to its own range. Draws the
 * baseline, a gradient fill, the line, and a ringed last point; the path sweeps in on
 * mount. Sparse x-labels (first / last) keep it uncluttered.
 */
export function TrendArea({
    data,
    height = 116,
    stroke = '#0ABFBF',
}: {
    data: { label: string; value: number }[];
    height?: number;
    stroke?: string;
}) {
    const gradientId = useId();
    const drawn = useDrawn();
    const width = 320;
    const pad = { top: 12, right: 10, bottom: 20, left: 10 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    if (data.length === 0) {
        return null;
    }

    const max = Math.max(...data.map((d) => d.value), 1);
    const step = data.length > 1 ? innerW / (data.length - 1) : 0;

    const coords = data.map((d, i) => {
        const x = pad.left + i * step;
        const y = pad.top + innerH - (d.value / max) * innerH;

        return { x, y, ...d };
    });

    const line = coords
        .map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`)
        .join(' ');
    const area = `${pad.left},${pad.top + innerH} ${line} ${(pad.left + innerW).toFixed(1)},${pad.top + innerH}`;
    const last = coords[coords.length - 1];
    const peak = coords.reduce(
        (a, b) => (b.value > a.value ? b : a),
        coords[0],
    );

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className={cn(
                'h-28 w-full overflow-visible transition-opacity duration-700',
                drawn ? 'opacity-100' : 'opacity-0',
            )}
            role="img"
            aria-label="Present headcount, last 14 days"
        >
            <defs>
                <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={stroke} stopOpacity="0.24" />
                    <stop offset="100%" stopColor={stroke} stopOpacity="0" />
                </linearGradient>
            </defs>

            <line
                x1={pad.left}
                x2={width - pad.right}
                y1={pad.top + innerH}
                y2={pad.top + innerH}
                className="stroke-border"
                strokeWidth={1}
            />

            <polygon points={area} fill={`url(#${gradientId})`} />
            <polyline
                points={line}
                fill="none"
                stroke={stroke}
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
            />

            {/* Peak marker. */}
            <circle
                cx={peak.x}
                cy={peak.y}
                r={2.5}
                fill={stroke}
                fillOpacity={0.5}
            />

            {/* Last point, ringed. */}
            <circle
                cx={last.x}
                cy={last.y}
                r={6}
                fill={stroke}
                fillOpacity={0.16}
            />
            <circle cx={last.x} cy={last.y} r={3} fill={stroke} />

            {coords.map((p) => (
                <circle
                    key={p.label}
                    cx={p.x}
                    cy={p.y}
                    r={8}
                    fill="transparent"
                >
                    <title>
                        {p.label}: {p.value}
                    </title>
                </circle>
            ))}

            <text
                x={pad.left}
                y={height - 4}
                className="fill-muted-foreground text-[9px]"
            >
                {coords[0].label}
            </text>
            <text
                x={width - pad.right}
                y={height - 4}
                textAnchor="end"
                className="fill-muted-foreground text-[9px]"
            >
                {last.label}
            </text>
        </svg>
    );
}

export type Bar = { label: string; value: number; hint?: string };

/**
 * A ranked list of horizontal bars, each width-proportional to the largest value.
 * Used for the busiest departments and the recruitment funnel.
 */
export function BarList({
    bars,
    color = '#0ABFBF',
}: {
    bars: Bar[];
    color?: string;
}) {
    const drawn = useDrawn();
    const max = Math.max(...bars.map((b) => b.value), 1);

    return (
        <ul className="flex flex-col gap-3">
            {bars.map((bar) => (
                <li key={bar.label} className="flex flex-col gap-1">
                    <div className="flex items-baseline justify-between gap-2 text-sm">
                        <span className="truncate text-muted-foreground">
                            {bar.label}
                        </span>
                        <span className="font-semibold tabular-nums">
                            {bar.value.toLocaleString()}
                            {bar.hint && (
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    {bar.hint}
                                </span>
                            )}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full"
                            style={{
                                width: drawn
                                    ? `${Math.max((bar.value / max) * 100, 2)}%`
                                    : '0%',
                                background: color,
                                transition: reduceMotion
                                    ? undefined
                                    : 'width 0.8s cubic-bezier(0.22, 1, 0.36, 1)',
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
