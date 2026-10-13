import { useId } from 'react';
import {
    MARK_VIEWBOX,
    dots,
    gradients,
    swooshBottom,
    swooshTop,
} from '../lib/mark-geometry';

/** The five gradients, with ids prefixed so several marks can share a page. */
export function MarkGradients({
    prefix,
    reversed = false,
}: {
    prefix: string;
    reversed?: boolean;
}) {
    return (
        <defs>
            {gradients.map((g, i) => {
                const [from, to] = reversed ? g.dark : g.light;

                return (
                    <linearGradient
                        key={i}
                        id={`${prefix}-g${i}`}
                        x1={g.x1}
                        y1={g.y1}
                        x2={g.x2}
                        y2={g.y2}
                        gradientUnits="userSpaceOnUse"
                    >
                        <stop stopColor={from} />
                        <stop offset="1" stopColor={to} />
                    </linearGradient>
                );
            })}
        </defs>
    );
}

export function Mark({
    className,
    reversed = false,
    title = 'SYNAPSE',
}: {
    className?: string;
    reversed?: boolean;
    title?: string | null;
}) {
    const prefix = useId().replace(/:/g, '');
    const fill = (i: number) => `url(#${prefix}-g${i})`;

    return (
        <svg
            viewBox={MARK_VIEWBOX}
            className={className}
            role={title ? 'img' : undefined}
            aria-hidden={title ? undefined : true}
            aria-label={title ?? undefined}
        >
            <MarkGradients prefix={prefix} reversed={reversed} />
            <circle {...dots.top} fill={fill(0)} />
            <path d={swooshTop} fill={fill(1)} />
            <circle {...dots.middle} fill={fill(2)} />
            <path d={swooshBottom} fill={fill(3)} />
            <ellipse
                cx={dots.bottom.cx}
                cy={dots.bottom.cy}
                rx={dots.bottom.rx}
                ry={dots.bottom.ry}
                transform={`rotate(${dots.bottom.rotate} ${dots.bottom.cx} ${dots.bottom.cy})`}
                fill={fill(4)}
            />
        </svg>
    );
}

/** Mark + wordmark, as the ERP's header sets it. */
export function Lockup({
    reversed = false,
    className = '',
}: {
    reversed?: boolean;
    className?: string;
}) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <Mark reversed={reversed} className="h-7 w-auto" title={null} />
            <span className="text-[0.95rem] font-[750] tracking-[0.14em] font-wide">
                SYNAPSE
            </span>
        </span>
    );
}
