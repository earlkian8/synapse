import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The paper a person works on at the kiosk — the half of the screen that is
 * theirs. `footer` sits under it on the navy, for the way out.
 */
export function CardPanel({
    children,
    footer,
    className,
}: {
    children: ReactNode;
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <div className="flex flex-col gap-4">
            <section
                className={cn(
                    'rounded-[1.75rem] bg-(--k-paper) p-5 text-(--k-ink) shadow-[0_24px_60px_-28px_rgb(0_0_0/0.55)] sm:p-7',
                    className,
                )}
            >
                {children}
            </section>
            {footer}
        </div>
    );
}

/** What went wrong, said under the keypad — read aloud as it appears. */
export function KioskMessage({
    children,
    tone = 'refused',
    surface = 'paper',
}: {
    children: ReactNode;
    tone?: 'refused' | 'note';
    /** The card's paper, or the kiosk's navy around it. */
    surface?: 'paper' | 'navy';
}) {
    return (
        <p
            role="alert"
            className={cn(
                'rounded-xl px-3.5 py-2.5 text-[0.95rem] leading-snug',
                surface === 'paper' &&
                    (tone === 'refused'
                        ? 'bg-[#B4233A]/8 text-[#8F1B2E]'
                        : 'bg-(--k-ink)/5 text-(--k-muted)'),
                surface === 'navy' &&
                    (tone === 'refused'
                        ? 'bg-[#F06A7E]/15 text-[#FFC9D1]'
                        : 'bg-white/8 text-white/80'),
            )}
        >
            {children}
        </p>
    );
}

/** A bar that drains over `ms` — how long until the kiosk moves on by itself. */
export function DrainBar({ ms, label }: { ms: number; label: string }) {
    return (
        <div
            role="timer"
            aria-label={label}
            className="h-1 overflow-hidden rounded-full bg-white/10"
        >
            <div
                style={{ animationDuration: `${ms}ms` }}
                className="h-full origin-left rounded-full bg-(--k-signal)/70 motion-safe:animate-[kiosk-drain_linear_both] motion-reduce:hidden"
            />
        </div>
    );
}
