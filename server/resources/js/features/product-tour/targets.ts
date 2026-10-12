import type { AppNavSection } from '@/lib/app-navigation';

/**
 * Everything the tour can point at. Each is marked in the app shell with
 * `{...tourTarget('…')}`, so the tour finds the element itself rather than a
 * copy of where it thinks the element is.
 */
export type TourTarget =
    | 'workspace'
    | `nav-${AppNavSection}`
    | 'search'
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

    // A target can be marked twice — the wide search field and the icon that
    // replaces it on a narrow bar — of which only one shows at a time.
    const elements = document.querySelectorAll<HTMLElement>(
        `[data-tour="${target}"]`,
    );

    for (const element of elements) {
        // `checkVisibility` sees `display: none` on any ancestor; browsers that
        // predate it (Safari before 17.4) fall back to the size check alone.
        if (
            typeof element.checkVisibility === 'function' &&
            !element.checkVisibility()
        ) {
            continue;
        }

        const rect = element.getBoundingClientRect();

        if (rect.width > 0 && rect.height > 0) {
            return element;
        }
    }

    return null;
}
