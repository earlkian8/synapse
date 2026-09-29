import { usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { ArrowRight, Clock3 } from 'lucide-react';
import { useRef } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import SynapseField from '@/components/synapse-field';
import { Button } from '@/components/ui/button';
import type { ProductTourApi } from '../use-product-tour';
import { TourDialog } from './tour-dialog';

/**
 * The opening card. It answers what somebody wonders the moment it appears —
 * what is this, how long is it, can I get out of it — before the first stop,
 * and lists the stops themselves so the length is visible, not promised.
 *
 * Skipping is as prominent as starting: nobody should feel held hostage by a
 * tutorial on their first minute in the app.
 */
export function TourWelcome({ tour }: { tour: ProductTourApi }) {
    const { auth } = usePage().props;
    const primary = useRef<HTMLButtonElement>(null);

    const firstVisit = tour.trigger === 'auto';
    const organization = auth.organization?.name ?? 'your company';
    const count = tour.stops.length;

    return (
        <TourDialog
            onEscape={tour.skip}
            onOpenAutoFocus={(event) => {
                event.preventDefault();
                primary.current?.focus();
            }}
            onKeyDown={(event) => {
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    tour.next();
                }
            }}
        >
            <div className="relative shrink-0 overflow-hidden bg-[#0F2044] px-6 pt-6 pb-5 text-white">
                <SynapseField />
                <div className="relative">
                    <AppLogoIcon surface="dark" className="h-8 w-auto" />
                    <p className="mt-5 text-[11px] font-semibold tracking-[0.14em] text-[#0ABFBF] uppercase">
                        {firstVisit ? 'Welcome to SYNAPSE' : 'Product tour'}
                    </p>
                    <DialogPrimitive.Title className="mt-1.5 text-xl leading-snug font-semibold tracking-tight">
                        {firstVisit
                            ? `Hi ${auth.user.first_name}, let's show you around.`
                            : 'A quick look around.'}
                    </DialogPrimitive.Title>
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-6 pt-5">
                <DialogPrimitive.Description className="text-sm leading-relaxed text-muted-foreground">
                    {firstVisit
                        ? `You're in ${organization}. In about a minute we'll point out where everything you can use lives, and where to find help later.`
                        : `Where everything you can use in ${organization} lives, and where to find help later.`}
                </DialogPrimitive.Description>

                {count > 0 && (
                    <>
                        <p className="mt-5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            What we'll cover
                        </p>
                        <ul className="mt-2 flex flex-wrap gap-1.5">
                            {tour.stops.map((stop) => (
                                <li
                                    key={stop.id}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-muted/40 py-1 pr-2.5 pl-2 text-xs text-foreground dark:border-border"
                                >
                                    <stop.icon className="size-3.5 text-[#0ABFBF]" />
                                    {stop.label}
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                    <Clock3 className="size-3.5 shrink-0" />
                    {count === 1 ? '1 stop' : `${count} stops`} · about a minute
                    · leave any time with Esc
                </p>
            </div>

            <div className="mt-6 flex shrink-0 flex-col-reverse gap-2 border-t border-border/70 px-6 py-4 sm:flex-row sm:items-center dark:border-border">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={tour.skip}
                    className="text-muted-foreground"
                >
                    {firstVisit ? 'Skip for now' : 'Not now'}
                </Button>
                <Button
                    ref={primary}
                    type="button"
                    onClick={tour.next}
                    className="sm:ml-auto"
                >
                    {count > 0 ? 'Show me around' : 'Continue'}
                    <ArrowRight className="size-4" />
                </Button>
            </div>
        </TourDialog>
    );
}
