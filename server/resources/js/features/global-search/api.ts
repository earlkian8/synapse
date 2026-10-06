import type { SearchGroup, SearchResponse } from './types';

/** The palette asks nothing until this many characters are typed. */
export const MIN_QUERY_LENGTH = 2;

/** Mirrors `GlobalSearchRequest`'s `max:80`. */
export const MAX_QUERY_LENGTH = 80;

/** Why a search failed, in words the palette can show as they are. */
export class SearchError extends Error {
    constructor(readonly status: number) {
        super(
            status === 429
                ? 'That was a lot of searches at once. Wait a moment, then type again.'
                : status === 401 || status === 419
                  ? 'Your session has ended. Reload the page to sign in again.'
                  : 'Search is not answering right now. Try again in a moment.',
        );
    }
}

/**
 * Search everything the signed-in person may open. A plain GET, so no CSRF
 * token is needed; aborted when a newer search supersedes it.
 */
export async function searchEverything(
    query: string,
    signal: AbortSignal,
): Promise<SearchGroup[]> {
    const response = await fetch(`/search?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new SearchError(response.status);
    }

    return ((await response.json()) as SearchResponse).groups;
}
