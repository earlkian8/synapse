import { motion, useReducedMotion } from 'motion/react';
import { useId } from 'react';
import {
    MARK_VIEWBOX,
    dots,
    swooshBottom,
    swooshTop,
} from '../lib/mark-geometry';
import { MarkGradients } from './mark';

/**
 * The hero's mark: built piece by piece on load (the swooshes traced, the dots
 * landing in order), then a signal fires through it for good: top dot, along
 * the upper swoosh, across the gap to the middle dot, along the lower swoosh,
 * across to the bottom dot. A ring opens at each dot as the signal arrives.
 *
 * The firing is CSS keyframes on one shared cycle (see index.css), so the rings
 * stay in step with the pulse without a timer. Reduced motion shows the mark
 * finished and still.
 */

// The signal's route, in the mark's own coordinates. Each runs along the middle
// of its swoosh and jumps the gap to the next dot.
const routeIn =
    'M439.75 70.28C410 52 382 40 352 38C330 40 318 46 300 51C255 62 220 70 186 83C130 108 92 140 77 180C62 220 60 250 72 275C85 305 110 332 136 347C150 356 160 362 168 364C200 340 230 305 252.98 286.82';
const routeOut =
    'M252.98 286.82C290 260 315 235 340 224C380 232 420 262 440 300C455 330 455 380 435 420C410 465 360 495 300 512C255 525 210 540 172 546C140 535 105 522 75.91 512.3';

const ease = [0.22, 1, 0.36, 1] as const;

export function FiringMark({ className }: { className?: string }) {
    const prefix = useId().replace(/:/g, '');
    const reduce = useReducedMotion();
    const fill = (i: number) => `url(#${prefix}-g${i})`;

    const draw = (delay: number) =>
        reduce
            ? {}
            : {
                  initial: { pathLength: 0, opacity: 0 },
                  animate: { pathLength: 1, opacity: [0, 1, 1, 0] },
                  transition: {
                      pathLength: { duration: 1.25, delay, ease },
                      opacity: {
                          duration: 1.9,
                          delay,
                          times: [0, 0.1, 0.7, 1],
                      },
                  },
              };

    const flood = (delay: number) =>
        reduce
            ? {}
            : {
                  initial: { opacity: 0 },
                  animate: { opacity: 1 },
                  transition: { duration: 0.7, delay, ease },
              };

    const land = (delay: number) =>
        reduce
            ? {}
            : {
                  initial: { scale: 0, opacity: 0 },
                  animate: { scale: 1, opacity: 1 },
                  transition: {
                      delay,
                      type: 'spring' as const,
                      stiffness: 260,
                      damping: 17,
                  },
              };

    const pieceStyle = {
        transformBox: 'fill-box',
        transformOrigin: 'center',
    } as const;

    return (
        <svg
            viewBox={MARK_VIEWBOX}
            className={`overflow-visible ${className ?? ''}`}
            aria-hidden="true"
        >
            <MarkGradients prefix={prefix} />
            <defs>
                <filter
                    id={`${prefix}-glow`}
                    x="-50%"
                    y="-50%"
                    width="200%"
                    height="200%"
                >
                    <feGaussianBlur stdDeviation="9" />
                </filter>
            </defs>

            {/* Rings that open as the signal reaches each dot. */}
            <g className={reduce ? 'hidden' : undefined}>
                <circle
                    {...dots.top}
                    className="firing-ring firing-ring-top"
                    style={pieceStyle}
                />
                <circle
                    {...dots.middle}
                    className="firing-ring firing-ring-middle"
                    style={pieceStyle}
                />
                <circle
                    cx={dots.bottom.cx}
                    cy={dots.bottom.cy}
                    r={dots.bottom.rx}
                    className="firing-ring firing-ring-bottom"
                    style={pieceStyle}
                />
            </g>

            {/* The swooshes: traced in sky, then filled. */}
            <motion.path
                d={swooshTop}
                fill="none"
                stroke="#37B3FF"
                strokeWidth={2.5}
                {...draw(0.15)}
                className={reduce ? 'hidden' : undefined}
            />
            <motion.path d={swooshTop} fill={fill(1)} {...flood(0.95)} />
            <motion.path
                d={swooshBottom}
                fill="none"
                stroke="#37B3FF"
                strokeWidth={2.5}
                {...draw(0.55)}
                className={reduce ? 'hidden' : undefined}
            />
            <motion.path d={swooshBottom} fill={fill(3)} {...flood(1.3)} />

            {/* The dots land in the order the signal will visit them. */}
            <motion.circle
                {...dots.top}
                fill={fill(0)}
                style={pieceStyle}
                {...land(0.35)}
            />
            <motion.circle
                {...dots.middle}
                fill={fill(2)}
                style={pieceStyle}
                {...land(0.75)}
            />
            <g
                transform={`rotate(${dots.bottom.rotate} ${dots.bottom.cx} ${dots.bottom.cy})`}
            >
                <motion.ellipse
                    cx={dots.bottom.cx}
                    cy={dots.bottom.cy}
                    rx={dots.bottom.rx}
                    ry={dots.bottom.ry}
                    fill={fill(4)}
                    style={pieceStyle}
                    {...land(1.15)}
                />
            </g>

            {/* The signal: a short bright dash running each route, with a
                blurred copy under it for the glow. */}
            {!reduce && (
                <g strokeLinecap="round" fill="none">
                    {[routeIn, routeOut].map((d, i) => (
                        <g
                            key={i}
                            className={
                                i === 0 ? 'firing-pulse-in' : 'firing-pulse-out'
                            }
                        >
                            <path
                                d={d}
                                pathLength={1}
                                className="firing-dash"
                                stroke="#BFE6FF"
                                strokeWidth={30}
                                filter={`url(#${prefix}-glow)`}
                            />
                            <path
                                d={d}
                                pathLength={1}
                                className="firing-dash"
                                stroke="#FFFFFF"
                                strokeWidth={9}
                            />
                        </g>
                    ))}
                </g>
            )}
        </svg>
    );
}
