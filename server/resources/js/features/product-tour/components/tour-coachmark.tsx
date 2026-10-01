import * as DialogPrimitive from '@radix-ui/react-dialog';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useId, useRef } from 'react';
import type { KeyboardEvent, Ref } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverArrow,
    PopoverContent,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { TourRect, TourStop } from '../types';
import type { ProductTourApi } from '../use-product-tour';
import { TourDialog } from './tour-dialog';

/** How many of a section's screens a card lists before "and N more". */
const LISTED = 6;

type Props = {
    tour: ProductTourApi;
    step: TourStop;
    /** The spotlight around the target, or null when it is off screen. */
    anchor: TourRect | null;
};

/**
 * One stop: a card beside the thing it describes, with the way on, the way
 * back and the way out. The target is lit by the spotlight; this card is what
 * holds focus, so the keyboard works it too — the arrow keys step through and
 * Escape leaves.
 *
 * If the target leaves the screen mid-tour (the window was narrowed until the
 * sidebar folded away), the same card is shown in the middle instead of
 * pointing at nothing.
 */
export function TourCoachmark({ tour, step, anchor }: Props) {
    const primary = useRef<HTMLButtonElement>(null);
    const titleId = useId();
    const bodyId = useId();

    // The card opens onto its forward button, so Enter walks the tour.
    const focusPrimary = (event: Event) => {
        event.preventDefault();
        primary.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            tour.next();
        } else if (event.key === 'ArrowLeft' && tour.index > 0) {
            event.preventDefault();
            tour.back();
        }
    };

    if (!anchor) {
        return (
            <TourDialog
                key={step.id}
                onEscape={tour.skip}
                onOpenAutoFocus={focusPrimary}
                onKeyDown={onKeyDown}
                className="w-[min(24rem,calc(100vw-2rem))] rounded-xl"
            >
                <StopCard
                    tour={tour}
                    step={step}
                    primaryRef={primary}
                    inDialog
                />
            </TourDialog>
        );
    }

    return (
        <Popover open modal>
            <PopoverAnchor asChild>
                <div
                    aria-hidden
                    className="pointer-events-none fixed"
                    style={anchor}
                />
            </PopoverAnchor>
            <PopoverContent
                // A fresh card per stop, so each one animates in from its
                // target's side and takes focus anew.
                key={step.id}
                side={step.side}
                align={step.align}
                sideOffset={12}
                collisionPadding={12}
                updatePositionStrategy="always"
                onOpenAutoFocus={focusPrimary}
                onEscapeKeyDown={(event) => {
                    event.preventDefault();
                    tour.skip();
                }}
                // As with the centred cards: only a button or Escape leaves.
                onInteractOutside={(event) => event.preventDefault()}
                onKeyDown={onKeyDown}
                role="dialog"
                aria-modal
                aria-labelledby={titleId}
                aria-describedby={bodyId}
                className="z-[70] w-[min(22rem,calc(100vw-1.5rem))] rounded-xl p-0 shadow-2xl shadow-black/25 duration-200 motion-reduce:animate-none"
            >
                {/* The card scrolls inside itself when the screen is short; the
                    arrow sits outside that, so it is never clipped. */}
                <div className="max-h-(--radix-popover-content-available-height) overflow-y-auto">
                    <StopCard
                        tour={tour}
                        step={step}
                        primaryRef={primary}
                        titleId={titleId}
                        bodyId={bodyId}
                    />
                </div>
                <PopoverArrow width={16} height={8} />
            </PopoverContent>
        </Popover>
    );
}

function StopCard({
    tour,
    step,
    primaryRef,
    titleId,
    bodyId,
    inDialog = false,
}: {
    tour: ProductTourApi;
    step: TourStop;
    primaryRef: Ref<HTMLButtonElement>;
    titleId?: string;
    bodyId?: string;
    /** Inside a dialog, the title and body are the dialog's own. */
    inDialog?: boolean;
}) {
    const Title = inDialog ? DialogPrimitive.Title : 'h2';
    const Body = inDialog ? DialogPrimitive.Description : 'p';
    const items = step.items ?? [];
    const listed = items.length > LISTED + 1 ? items.slice(0, LISTED) : items;
    const more = items.length - listed.length;

    return (
        <div className="flex flex-col">
            <div className="flex items-start gap-3 px-4 pt-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <step.icon className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1 pt-px">
                    <p className="text-[10.5px] font-semibold tracking-[0.14em] text-muted-foreground uppercase tabular-nums">
                        {tour.stopNumber} of {tour.stops.length}
                    </p>
                    <Title
                        id={titleId}
                        className="mt-0.5 text-[15px] leading-snug font-semibold tracking-tight text-foreground"
                    >
                        {step.title}
                    </Title>
                </div>
                <button
                    type="button"
                    onClick={tour.skip}
                    className="-mt-0.5 -mr-1 shrink-0 rounded-md px-1.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    Skip tour
                </button>
            </div>

            <Body
                id={bodyId}
                className="px-4 pt-2.5 text-[13px] leading-relaxed text-muted-foreground"
            >
                {step.body}
            </Body>

            {listed.length > 0 && (
                <ul className="mx-4 mt-3 overflow-hidden rounded-lg border border-border/70 bg-muted/30 dark:border-border">
                    {listed.map((item) => (
                        <li
                            key={item.title}
                            className="flex items-center gap-2.5 border-b border-border/60 px-2.5 py-2 last:border-b-0"
                        >
                            {item.icon && (
                                <item.icon className="size-4 shrink-0 text-muted-foreground" />
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13px] leading-tight font-medium text-foreground">
                                    {item.title}
                                </span>
                                <span className="block truncate text-[11.5px] leading-snug text-muted-foreground">
                                    {item.summary}
                                </span>
                            </span>
                        </li>
                    ))}
                    {more > 0 && (
                        <li className="px-2.5 py-1.5 text-[11.5px] text-muted-foreground">
                            and {more} more in this section
                        </li>
                    )}
                </ul>
            )}

            <div className="mt-4 flex items-center gap-3 border-t border-border/70 px-4 py-3 dark:border-border">
                <ProgressDots
                    total={tour.stops.length}
                    current={tour.stopNumber}
                />
                <div className="ml-auto flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={tour.back}
                        className="text-muted-foreground"
                    >
                        <ArrowLeft className="size-3.5" />
                        Back
                    </Button>
                    <Button
                        ref={primaryRef}
                        type="button"
                        size="sm"
                        onClick={tour.next}
                    >
                        Next
                        <ArrowRight className="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Where the tour is, as dots — the current one drawn long in the brand teal,
 * the ones passed filled, the ones ahead hollow. Decorative: the "n of N"
 * above says the same in words.
 */
function ProgressDots({ total, current }: { total: number; current: number }) {
    return (
        <div aria-hidden className="flex min-w-0 items-center gap-1">
            {Array.from({ length: total }, (_, index) => {
                const position = index + 1;

                return (
                    <span
                        key={position}
                        className={cn(
                            'h-1.5 rounded-full transition-all duration-300 motion-reduce:transition-none',
                            position === current
                                ? 'w-4 bg-[#0ABFBF]'
                                : position < current
                                  ? 'w-1.5 bg-[#0ABFBF]/45'
                                  : 'w-1.5 bg-muted-foreground/25',
                        )}
                    />
                );
            })}
        </div>
    );
}
