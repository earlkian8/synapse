import { useMemo } from 'react';
import type { QrMatrix as Matrix } from '../types';

/**
 * A QR code drawn from the grid the server worked out (ADR 0103): one path of
 * dark runs, row by row, with a quiet margin — crisp at any size, and a few
 * hundred bytes to send instead of a picture.
 */
export function QrMatrix({
    matrix,
    label,
    className,
}: {
    matrix: Matrix;
    label: string;
    className?: string;
}) {
    const path = useMemo(() => drawn(matrix), [matrix]);
    const margin = 2;

    return (
        <svg
            role="img"
            aria-label={label}
            viewBox={`${-margin} ${-margin} ${matrix.size + margin * 2} ${matrix.size + margin * 2}`}
            shapeRendering="crispEdges"
            className={className}
        >
            <rect
                x={-margin}
                y={-margin}
                width={matrix.size + margin * 2}
                height={matrix.size + margin * 2}
                fill="#fff"
            />
            <path d={path} fill="#0A1628" />
        </svg>
    );
}

/** The dark modules as one path: each run of them in a row, one rectangle. */
function drawn({ size, bits }: Matrix): string {
    const bytes = Uint8Array.from(atob(bits), (char) => char.charCodeAt(0));
    const dark = (index: number) =>
        ((bytes[index >> 3] ?? 0) >> (7 - (index & 7))) & 1;
    let d = '';

    for (let y = 0; y < size; y++) {
        let x = 0;

        while (x < size) {
            if (!dark(y * size + x)) {
                x++;
                continue;
            }

            const start = x;

            while (x < size && dark(y * size + x)) {
                x++;
            }

            d += `M${start} ${y}h${x - start}v1h${start - x}z`;
        }
    }

    return d;
}
