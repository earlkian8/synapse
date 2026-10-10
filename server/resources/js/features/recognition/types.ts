/** A colleague as recognition shows them — nothing more than a name card. */
export type Person = {
    id: number;
    name: string;
    initials: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

/** One item on the wall: kudos between colleagues, or an award given. */
export type FeedItem = {
    kind: 'kudos' | 'award';
    id: number;
    at: string | null;
    awarded_on?: string | null;
    from: Person | null;
    to: Person | null;
    message: string | null;
    points: number;
    award_type?: { name: string; color: string | null } | null;
    can_remove: boolean;
};

/** The person's own standing. */
export type RecognitionMe = {
    has_employee: boolean;
    balance: number;
    kudos_left: number;
    kudos_points: number;
    kudos_monthly_limit: number;
};

export type NominatableType = {
    id: number;
    name: string;
    description: string | null;
    color: string | null;
    points: number;
};

export type NominationStatus =
    'pending' | 'approved' | 'rejected' | 'withdrawn';

export type Nomination = {
    id: number;
    status: NominationStatus;
    reason: string;
    created_at: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    nominee: Person | null;
    nominator: string | null;
    reviewer: string | null;
    award_type: {
        id: number;
        name: string;
        color: string | null;
        points: number;
    } | null;
    award: {
        id: number;
        reason: string | null;
        awarded_on: string | null;
    } | null;
    is_mine?: boolean;
};

export type Reward = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    cost: number;
    stock: number | null;
    is_active: boolean;
    is_archived: boolean;
    affordable: boolean | null;
    redeemed?: number;
};

export type RedemptionStatus =
    'pending' | 'fulfilled' | 'declined' | 'cancelled';

export type Redemption = {
    id: number;
    status: RedemptionStatus;
    cost: number;
    note: string | null;
    response_note: string | null;
    created_at: string | null;
    handled_at: string | null;
    reward: { name: string } | null;
    employee: Person | null;
    handler: string | null;
    is_mine?: boolean;
};

export type LedgerLine = {
    id: number;
    amount: number;
    kind: 'award' | 'kudos' | 'redemption' | 'refund' | 'adjustment';
    note: string | null;
    at: string | null;
};

export type WallPageProps = {
    feed: FeedItem[];
    me: RecognitionMe;
    colleagues: Person[];
    types: NominatableType[];
    can: { manage: boolean };
};

export type MyNominationsPageProps = {
    nominations: Nomination[];
    me: RecognitionMe;
    colleagues: Person[];
    types: NominatableType[];
};

export type MyRewardsPageProps = {
    me: RecognitionMe;
    rewards: Reward[];
    redemptions: Redemption[];
    history: LedgerLine[];
};

export type NominationQueuePageProps = {
    nominations: Nomination[];
    counts: { pending: number; approved: number; rejected: number };
    ai_available: boolean;
};

export type RewardsDeskPageProps = {
    rewards: Reward[];
    redemptions: Redemption[];
    counts: { pending: number };
    balances: { employee: Person; balance: number }[];
    employees: Person[];
    settings: { kudos_points: number; kudos_monthly_limit: number };
    pending_nominations: number;
};
