import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** The query parameter that names the record a list page should open. */
const PARAM = 'open';

/** `url` without its `open` parameter, as a path and query. */
function withoutParam(url: string): string {
    const next = new URL(url, 'http://localhost');
    next.searchParams.delete(PARAM);

    return next.pathname + next.search + next.hash;
}

/**
 * Open the record an address names — `/employees?…&open=12` — on a list page
 * whose records otherwise open only in a drawer (ADR 0069). Global search links
 * here; any other link can too.
 *
 * The page passes its rows, how a row is keyed in the address, and the handler
 * its own rows call to open their drawer. The first render for such an address
 * opens the row (in the render-phase "derived state" style these pages already
 * use, never a state-syncing effect), and `open` is then dropped from the
 * address with a client-side replace: the drawer is open, and a reload, the
 * redirect after an action taken in the drawer, or Back does not open it again.
 * A key that matches no row on the page is ignored.
 */
export function useLinkedRecord<T>(
    rows: readonly T[],
    keyOf: (row: T) => string | number,
    open: (row: T) => void,
): void {
    const { url } = usePage();
    const linked = new URL(url, 'http://localhost').searchParams.get(PARAM);
    const [handled, setHandled] = useState<string | null>(null);

    if (linked !== null && handled !== url) {
        setHandled(url);

        const row = rows.find(
            (candidate) => String(keyOf(candidate)) === linked,
        );

        if (row !== undefined) {
            open(row);
        }
    }

    // Forget the last address once its `open` is gone, so following the same
    // link again (the page may be kept rather than remounted) opens it again.
    if (linked === null && handled !== null) {
        setHandled(null);
    }

    useEffect(() => {
        if (linked !== null) {
            router.replace({
                url: withoutParam(url),
                preserveState: true,
                preserveScroll: true,
            });
        }
    }, [linked, url]);
}
