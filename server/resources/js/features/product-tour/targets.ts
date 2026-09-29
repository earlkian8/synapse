import type { AppNavSection } from '@/lib/app-navigation';

/**
 * Everything the tour can point at. Each is marked in the app shell with
 * `{...tourTarget('…')}`, so the tour finds the element itself rather than a
 * copy of where it thinks the element is.
 */
export type TourTarget =
    | 'workspace'
    | `nav-${AppNavSection}`
    | 'notifications'
    | 'help'
    | 'account'
    | 'assistant';

/** The attribute that marks an element as a place the tour can point at. */
export function tourTarget(target: TourTarget): { 'data-tour': TourTarget } {
    return { 'data-tour': target };
}

/**
 * The element marked as `target`, if it is on screen to be pointed at — not
 * rendered, hidden (the sidebar on a phone lives in a closed sheet), or laid
 * out with no size all read as absent, and the tour passes over that stop.
 */
export function findTourTarget(target: TourTarget): HTMLElement | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const element = document.querySelector<HTMLElement>(
        `[data-tour="${target}"]`,
    );

    if (!element) {
        return null;
    }

    // `checkVisibility` sees `display: none` on any ancestor; browsers that
    // predate it (Safari before 17.4) fall back to the size check alone.
    if (
        typeof element.checkVisibility === 'function' &&
        !element.checkVisibility()
    ) {
        return null;
    }

    const rect = element.getBoundingClientRect();

    return rect.width > 0 && rect.height > 0 ? element : null;
}
