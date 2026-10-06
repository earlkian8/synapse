/**
 * Global search (ADR 0069) — what `GET /search` answers: groups in a fixed
 * order, each holding the best few things of one kind the person may open.
 */
export type SearchResult = {
    /** Unique across groups, e.g. `employee:12`. */
    id: string;
    title: string;
    subtitle: string | null;
    /** A short aside on the right: an employee number, a status, a date. */
    hint: string | null;
    href: string;
};

export type SearchGroupKey =
    | 'screens'
    | 'employees'
    | 'applicants'
    | 'job-postings'
    | 'onboarding'
    | 'offboarding'
    | 'leave'
    | 'appraisals'
    | 'training'
    | 'events'
    | 'departments'
    | 'users'
    | 'roles'
    | 'help';

export type SearchGroup = {
    key: SearchGroupKey;
    label: string;
    items: SearchResult[];
};

export type SearchResponse = {
    query: string;
    groups: SearchGroup[];
};

export type SearchStatus = 'idle' | 'loading' | 'ready' | 'error';
