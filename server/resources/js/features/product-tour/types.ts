import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { AppNavItem } from '@/lib/app-navigation';
import type { TourTarget } from './targets';

/** How the tour ended. Mirrors `App\Support\ProductTour::OUTCOMES`. */
export type TourOutcome = 'completed' | 'skipped';

/**
 * Why the tour opened: offered on its own the first time somebody reaches the
 * app, or asked for again from the Help menu.
 */
export type TourTrigger = 'auto' | 'manual';

/**
 * What a run is (ADR 0080): the whole tour, or only the stops added since the
 * version the person last answered.
 */
export type TourKind = 'full' | 'whats_new';

/** How the server files a run. Mirrors `ProductTourRun::MODES`. */
export type TourRunMode = 'first' | 'whats_new' | 'replay';

/** Which side of its target a stop's card sits on. */
export type TourSide = 'top' | 'right' | 'bottom' | 'left';

/** One stop on the tour: a card beside something on the screen. */
export type TourStop = {
    kind: 'stop';
    id: TourTarget;
    target: TourTarget;
    /** The short name the welcome lists the stop under. */
    label: string;
    icon: LucideIcon;
    title: string;
    body: string;
    /** The sidebar section's screens, when the stop is one. */
    items?: AppNavItem[];
    side: TourSide;
    align: 'start' | 'center' | 'end';
    /** Room left around the target inside the spotlight, in pixels. */
    padding: number;
    /** The spotlight's corner radius — a round button gets a round light. */
    radius: number;
    /**
     * The tour version that added (or changed) this stop (ADR 0080). Somebody
     * who answered an earlier version is shown it in "What's new".
     */
    since: number;
};

/** The opening card, centred, before any stop. */
export type TourWelcome = { kind: 'welcome'; id: 'welcome' };

/** The closing card, centred, after every stop. */
export type TourFinish = { kind: 'finish'; id: 'finish' };

export type TourStep = TourWelcome | TourStop | TourFinish;

export type TourStepId = TourStep['id'];

/** A place to go next, offered on the closing card. */
export type TourNextStep = {
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
};

/** A viewport rectangle, in CSS pixels. */
export type TourRect = {
    top: number;
    left: number;
    width: number;
    height: number;
};
