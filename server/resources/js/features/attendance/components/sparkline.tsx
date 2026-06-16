import { useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * A tiny inline trend line (SVG), drawn from a series of values and normalised to
 * its own range. Used in the monthly report to show each employee's worked-hours
 * rhythm across the month. Renders nothing meaningful for an empty / flat series.
 */
export function Sparkline({
    data,
    width = 96,
    height = 28,
    className,
    stroke = 'var(--color-chart-1, #0ABFBF)',
}: {
    data: number[];
    width?: number;
    height?: number;
    className?: string;
    stroke?: string;
}) {
    const gradientId = useId();
    const points = data.length;

    if (points === 0) {
        return (
            <div
                className={cn(
                    'text-[11px] text-muted-foreground/60',
                    className,
                )}
                style={{ width }}
            >
                —
            </div>
        );
    }

    const max = Math.max(...data, 1);
    const pad = 2;
    const innerW = width - pad * 2;
    const innerH = height - pad * 2;
    const step = points > 1 ? innerW / (points - 1) : 0;

    const coords = data.map((value, index) => {
        const x = pad + index * step;
        const y = pad + innerH - (value / max) * innerH;

        return [x, y] as const;
    });

    const line = coords
        .map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`)
        .join(' ');
    const area = `${pad},${height - pad} ${line} ${(pad + innerW).toFixed(1)},${height - pad}`;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className={cn('overflow-visible', className)}
            role="img"
            aria-label="Worked-hours trend"
        >
            <defs>
                <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={stroke} stopOpacity="0.22" />
                    <stop offset="100%" stopColor={stroke} stopOpacity="0" />
                </linearGradient>
            </defs>
            <polygon points={area} fill={`url(#${gradientId})`} />
            <polyline
                points={line}
                fill="none"
                stroke={stroke}
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {points === 1 && (
                <circle
                    cx={coords[0][0]}
                    cy={coords[0][1]}
                    r="2"
                    fill={stroke}
                />
            )}
        </svg>
    );
}
