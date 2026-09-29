import { useEffect } from 'react';
import type { TourStep } from '../types';
import { useProductTour } from '../use-product-tour';
import type { ProductTourApi } from '../use-product-tour';
import { padRect, useTargetRect } from '../use-target-rect';
import { TourCoachmark } from './tour-coachmark';
import { TourFinish } from './tour-finish';
import { TourSpotlight } from './tour-spotlight';
import { TourWelcome } from './tour-welcome';

/** Let the page land (the dashboard rises in) before the tour opens over it. */
const OFFER_DELAY = 900;

/** How often to look again while something else holds the screen. */
const RETRY_DELAY = 1500;

/**
 * The first-run tour (ADR 0060). Mounted once in the app shell, beside the
 * assistant, so it is there on every page with a sidebar and on none without
 * one — company setup, the workspace picker and the sign-in screens never show
 * it.
 *
 * It offers itself once per page load to somebody the server says is owed it,
 * and otherwise waits to be started from the Help menu.
 */
export function ProductTour() {
    const tour = useProductTour();
    const { owed, offered, running, start } = tour;

    useEffect(() => {
        if (!owed || offered || running) {
            return;
        }

        let timer = 0;

        const offer = () => {
            // Never open over something already modal — a dialog a page opened
            // on arrival would fight the tour for focus. Look again shortly.
            if (
                document.querySelector(
                    '[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]',
                )
            ) {
                timer = window.setTimeout(offer, RETRY_DELAY);

                return;
            }

            start('auto');
        };

        timer = window.setTimeout(offer, OFFER_DELAY);

        return () => window.clearTimeout(timer);
    }, [owed, offered, running, start]);

    if (!running || !tour.step) {
        return null;
    }

    return <TourStage tour={tour} step={tour.step} />;
}

/**
 * One moment of the tour: the spotlight, and the card for the current step.
 * The spotlight stays mounted from the first step to the last, which is what
 * lets it glide between stops.
 */
function TourStage({ tour, step }: { tour: ProductTourApi; step: TourStep }) {
    const rect = useTargetRect(step.kind === 'stop' ? step.target : null);
    const anchor =
        step.kind === 'stop' && rect ? padRect(rect, step.padding) : null;

    return (
        <>
            <TourSpotlight
                rect={anchor}
                radius={step.kind === 'stop' ? step.radius : 9999}
            />
            {step.kind === 'welcome' && <TourWelcome tour={tour} />}
            {step.kind === 'stop' && (
                <TourCoachmark tour={tour} step={step} anchor={anchor} />
            )}
            {step.kind === 'finish' && <TourFinish tour={tour} />}
        </>
    );
}
