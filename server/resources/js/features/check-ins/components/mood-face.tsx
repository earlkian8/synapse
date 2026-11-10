import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { moodColor, moodWord } from '../constants';

/**
 * A face that wears a mood (ADR 0102). One drawing, every feature a function of the
 * mood from 1 (rough) to 5 (great): the body sags or lifts, the brows tilt, the eyes
 * soften into a smile, the mouth curves, cheeks warm — and the colour moves from
 * "feeling blue" to "sunny". Between two answers it eases rather than jumps, so a
 * change of mind reads as a change of face. With no mood yet it waits, drawn in
 * outline.
 *
 * It only ever shows the person's own mood answer. The other questions never move
 * it: a face that frowned at "I often think about leaving" would push people toward
 * the answers that please it.
 */
export function MoodFace({
    mood,
    size = 96,
    className,
    label,
}: {
    /** 1–5, or null while unanswered. */
    mood: number | null;
    size?: number;
    className?: string;
    /** Spoken by screen readers; defaults to the mood in words. */
    label?: string;
}) {
    const value = useEased(mood ?? 3);
    const waiting = mood === null;
    const v = (value - 3) / 2; // −1 (rough) … +1 (great)

    // The body: a pebble a little wider at the bottom when low, taller when high.
    const top = 16 - v * 3;
    const bottom = 106 + v * 1.5;
    const waist = 12 + -v * 3;
    const body = `M60 ${top} C${92 + waist * 0.2} ${top} 108 ${36 - v * 2} 108 ${62} C108 ${88 + waist * 0.4} ${90} ${bottom} 60 ${bottom} C${30} ${bottom} 12 ${88 + waist * 0.4} 12 62 C12 ${36 - v * 2} ${28 - waist * 0.2} ${top} 60 ${top} Z`;

    // Eyes narrow into a smile when high, droop when low.
    const eyeY = 52 + Math.max(0, -v) * 3;
    const eyeRy = 7.5 - Math.max(0, v) * 3.2 - Math.max(0, -v) * 1.5;
    // Brows only show past the middle, tilting inward-up when sad.
    const brow = Math.max(0, -v);
    const browLift = Math.max(0, v);
    // The mouth: a curve from frown to grin, wider as it smiles.
    const half = 15 + v * 4;
    const mouthY = 78 - v * 1.5;
    const curve = v * 13;
    const mouth = `M${60 - half} ${mouthY} Q60 ${mouthY + curve} ${60 + half} ${mouthY}`;

    const fill = waiting ? 'transparent' : moodColor(value);
    // Features in light ink on the deepest blue, dark everywhere else.
    const ink = !waiting && value < 1.7 ? '#ffffff' : 'var(--mood-ink)';

    return (
        <svg
            viewBox="0 0 120 120"
            width={size}
            height={size}
            role="img"
            aria-label={label ?? (waiting ? 'No answer yet' : moodWord(value))}
            className={cn('shrink-0 overflow-visible', className)}
        >
            <path
                d={body}
                fill={fill}
                stroke={waiting ? 'currentColor' : 'none'}
                strokeWidth={waiting ? 2.5 : 0}
                strokeDasharray={waiting ? '6 7' : undefined}
                className={waiting ? 'text-muted-foreground/50' : undefined}
            />
            {!waiting && (
                <g
                    stroke={ink}
                    fill="none"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    {brow > 0.05 && (
                        <g strokeWidth={3.5} opacity={Math.min(1, brow * 1.6)}>
                            <path
                                d={`M33 ${40 - brow * 2} L${49} ${36 - brow * 6}`}
                            />
                            <path
                                d={`M87 ${40 - brow * 2} L${71} ${36 - brow * 6}`}
                            />
                        </g>
                    )}
                    {browLift > 0.4 && (
                        <g strokeWidth={3} opacity={(browLift - 0.4) * 1.6}>
                            <path d="M34 37 Q42 31 50 36" />
                            <path d="M70 36 Q78 31 86 37" />
                        </g>
                    )}
                    <path d={mouth} strokeWidth={5.5} />
                </g>
            )}
            {!waiting && (
                <g fill={ink}>
                    <ellipse
                        cx={42}
                        cy={eyeY}
                        rx={6}
                        ry={Math.max(2.2, eyeRy)}
                    />
                    <ellipse
                        cx={78}
                        cy={eyeY}
                        rx={6}
                        ry={Math.max(2.2, eyeRy)}
                    />
                </g>
            )}
            {!waiting && v > 0.15 && (
                <g fill="#ffffff" opacity={Math.min(0.42, (v - 0.15) * 0.6)}>
                    <ellipse cx={28} cy={70} rx={8} ry={5} />
                    <ellipse cx={92} cy={70} rx={8} ry={5} />
                </g>
            )}
        </svg>
    );
}

/** `target`, eased toward over ~300 ms — at once when motion is reduced. */
function useEased(target: number): number {
    const [value, setValue] = useState(target);
    const from = useRef(target);

    useEffect(() => {
        const start = from.current;

        if (
            start === target ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            from.current = target;
            const id = requestAnimationFrame(() => setValue(target));

            return () => cancelAnimationFrame(id);
        }

        const began = performance.now();
        let frame = 0;
        const tick = (now: number) => {
            const t = Math.min(1, (now - began) / 300);
            // Ease out with the slightest overshoot, like something settling.
            const eased = 1 - Math.pow(1 - t, 3) * (1 - 0.6 * t);
            const next = start + (target - start) * eased;
            from.current = next;
            setValue(next);

            if (t < 1) {
                frame = requestAnimationFrame(tick);
            }
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [target]);

    return value;
}
