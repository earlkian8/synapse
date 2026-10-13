import { StatusPill } from '@/components/status-pill';
import { accountStatus } from '../api';
import type { Account } from '../api';

const PILLS = {
    active: { tone: 'emerald', label: 'Active' },
    unverified: { tone: 'sky', label: 'Unverified' },
    inactive: { tone: 'amber', label: 'Inactive' },
    archived: { tone: 'neutral', label: 'Archived' },
} as const;

/** Archived, inactive, waiting on email confirmation, or active. */
export function AccountStatusPill({ account }: { account: Account }) {
    const pill = PILLS[accountStatus(account)];

    return <StatusPill tone={pill.tone}>{pill.label}</StatusPill>;
}
