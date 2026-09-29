import * as DialogPrimitive from '@radix-ui/react-dialog';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    /** Escape — the tour decides whether that skips or finishes it. */
    onEscape: () => void;
    /** Where focus lands when the card opens (the forward button). */
    onOpenAutoFocus: (event: Event) => void;
    onKeyDown?: (event: KeyboardEvent<HTMLDivElement>) => void;
    className?: string;
};

/**
 * A tour card in the middle of the screen: the welcome, the send-off, and a
 * stop whose target has left the screen. Modal like every tour card — focus
 * stays inside it and the page underneath does not scroll or take clicks — and
 * it brings no backdrop of its own, because the spotlight is the backdrop.
 */
export function TourDialog({
    children,
    onEscape,
    onOpenAutoFocus,
    onKeyDown,
    className,
}: Props) {
    return (
        <DialogPrimitive.Root open modal>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Content
                    onOpenAutoFocus={onOpenAutoFocus}
                    onEscapeKeyDown={(event) => {
                        event.preventDefault();
                        onEscape();
                    }}
                    // A click on the dimmed page is not an answer: leaving the
                    // tour is always a button or Escape, never an accident.
                    onInteractOutside={(event) => event.preventDefault()}
                    onKeyDown={onKeyDown}
                    className={cn(
                        'fixed top-1/2 left-1/2 z-[70] flex max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 animate-in flex-col overflow-hidden rounded-2xl border border-border bg-card text-card-foreground shadow-2xl shadow-black/30 duration-300 fade-in-0 outline-none zoom-in-95 motion-reduce:animate-none',
                        className,
                    )}
                >
                    {children}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
