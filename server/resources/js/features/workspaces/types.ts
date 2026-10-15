/** One company the signed-in person belongs to, as the picker and Settings show it. */
export type MyWorkspace = {
    id: number;
    name: string;
    logo_url: string | null;
    initials: string;
    /** Their most senior role there, with "+N" when they hold more. */
    role: string | null;
    people_count: number;
    /** The day they joined it (YYYY-MM-DD), when known. */
    joined_on: string | null;
    is_default: boolean;
    /** The one this session is working in. */
    is_active: boolean;
    /** Settings only: whether they may leave it. */
    leaving?: {
        allowed: boolean;
        /** Why not, worded to be shown as it is. */
        reason: string | null;
        /** Nobody else is in it — leaving closes it for good. */
        alone: boolean;
    };
};
