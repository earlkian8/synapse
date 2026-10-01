import { Link, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { ArrowLeft, ArrowRight, Check, CircleHelp } from 'lucide-react';
import { useRef } from 'react';
import SynapseField from '@/components/synapse-field';
import { Button } from '@/components/ui/button';
import type { ProductTourApi } from '../use-product-tour';
import { TourDialog } from './tour-dialog';

/**
 * The send-off. A tour that ends on "that's all" leaves somebody where they
 * started, so this one ends on what to do first — picked for the person's role
 * ({@link buildNextSteps}) — and on where the tour lives from now on.
 *
 * Every way out of this card counts as finishing: all the stops were seen.
 */
export function TourFinish({ tour }: { tour: ProductTourApi }) {
    const { auth } = usePage().props;
    const primary = useRef<HTMLButtonElement>(null);

    const firstVisit = tour.trigger === 'auto';

    return (
        <TourDialog
            onEscape={tour.complete}
            onOpenAutoFocus={(event) => {
                event.preventDefault();
                primary.current?.focus();
            }}
            onKeyDown={(event) => {
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    tour.back();
                }
            }}
        >
            <div className="relative shrink-0 overflow-hidden bg-[#0F2044] px-6 pt-6 pb-5 text-white">
                <SynapseField />
                <div className="relative">
                    <span className="flex size-10 animate-[assistant-pop_0.5s_ease-out] items-center justify-center rounded-full bg-[#0ABFBF] text-[#0F2044] shadow-[0_0_24px_rgb(10_191_191/0.45)] motion-reduce:animate-none">
                        <Check className="size-5" strokeWidth={3} />
                    </span>
                    <p className="mt-5 text-[11px] font-semibold tracking-[0.14em] text-[#0ABFBF] uppercase">
                        Tour complete
                    </p>
                    <DialogPrimitive.Title className="mt-1.5 text-xl leading-snug font-semibold tracking-tight">
                        {firstVisit
                            ? `You're all set, ${auth.user.first_name}.`
                            : "That's the tour."}
                    </DialogPrimitive.Title>
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-6 pt-5">
                <DialogPrimitive.Description className="text-sm leading-relaxed text-muted-foreground">
                    Here's a good place to start:
                </DialogPrimitive.Description>

                <ul className="mt-3 flex flex-col gap-2">
                    {tour.nextSteps.map((next) => (
                        <li key={next.title}>
                            <Link
                                href={next.href}
                                onClick={tour.complete}
                                className="group flex items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none dark:border-border"
                            >
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                    <next.icon className="size-[18px]" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium text-foreground">
                                        {next.title}
                                    </span>
                                    <span className="block text-xs leading-relaxed text-muted-foreground">
                                        {next.description}
                                    </span>
                                </span>
                                <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                            </Link>
                        </li>
                    ))}
                </ul>

                <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                    <CircleHelp className="size-3.5 shrink-0" />
                    Replay the tour any time from Help in the top bar.
                </p>
            </div>

            <div className="mt-6 flex shrink-0 items-center gap-2 border-t border-border/70 px-6 py-4 dark:border-border">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={tour.back}
                    className="text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back
                </Button>
                <Button
                    ref={primary}
                    type="button"
                    onClick={tour.complete}
                    className="ml-auto"
                >
                    Start using SYNAPSE
                </Button>
            </div>
        </TourDialog>
    );
}
