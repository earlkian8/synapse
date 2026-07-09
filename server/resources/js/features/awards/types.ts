export type AwardEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type EmployeeAward = {
    id: number;
    awarded_on: string | null;
    reason: string | null;
    employee?: AwardEmployee | null;
    award_type?: {
        id: number;
        hashid: string;
        name: string;
        color: string | null;
    } | null;
    granted_by?: { id: number; name: string } | null;
};

export type AwardType = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    color: string | null;
    is_active: boolean;
    is_archived: boolean;
    awards_count: number;
};

export type AwardTypeOption = {
    id: number;
    name: string;
    color: string | null;
};

export type AwardableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type AwardStats = {
    total: number;
    this_month: number;
    recognized: number;
    types: number;
};

export type AwardPermissions = { manage: boolean };

export type AwardsIndexPageProps = {
    awards: EmployeeAward[];
    types: AwardTypeOption[];
    employees: AwardableEmployee[];
    stats: AwardStats;
    can: AwardPermissions;
};

// ── Nomination board ─────────────────────────────────────────────────────────

/** The signals the nominator scores; each has a fixed hue on the board. */
export type NominationSignalKey =
    | 'performance'
    | 'forecast'
    | 'attendance'
    | 'training'
    | 'tenure'
    | 'gap';

/** One scored signal in a nominee's transparent breakdown. */
export type NominationComponent = {
    key: NominationSignalKey;
    label: string;
    points: number;
    max: number;
    detail: string;
};

export type NominationBand = 'strong' | 'promising' | 'fair' | 'weak';

/** One ranked employee on an award type's shortlist. */
export type Nominee = {
    employee: AwardEmployee;
    rank: number;
    score: number;
    band: NominationBand;
    components: NominationComponent[];
    recent_winner: boolean;
    won_months_ago: number | null;
};

/** One award type's entry on the board: its focus profile + ranked shortlist. */
export type AwardNomination = {
    type: {
        id: number;
        name: string;
        description: string | null;
        color: string | null;
    };
    profile: { key: string; label: string; hint: string };
    nominees: Nominee[];
};

/** The AI citation draft result — a string or a renderable failure. */
export type CitationResult =
    | { available: true; citation: string }
    | { available: false; reason: string; retryable: boolean };

/** Pre-selection for the give dialog when opened from the nomination board. */
export type AwardPreset = { employeeId: number; typeId: number };

export type AwardNominationsPageProps = {
    board: AwardNomination[];
    employees: AwardableEmployee[];
    ai_available: boolean;
    can: AwardPermissions;
};
