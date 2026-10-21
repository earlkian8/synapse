import { useQuery } from '@tanstack/react-query';
import type { ActivityEntry } from '@/features/activity/api';
import type { Account } from '@/features/accounts/api';
import type { Organization } from '@/features/organizations/api';
import type { ProblemReport } from '@/features/problem-reports/api';
import { api } from '@/lib/api';

export type Overview = {
    stats: {
        organizations: number;
        organizations_this_month: number;
        in_setup: number;
        accounts: number;
        active_30d: number;
        unverified: number;
        employees: number;
        mobile_sessions: number;
        open_reports: number;
        new_reports: number;
    };
    open_reports: ProblemReport[];
    newest_organizations: Organization[];
    recent_sign_ins: Account[];
    recent_activity: ActivityEntry[] | null;
};

export function useOverview() {
    return useQuery({
        queryKey: ['overview'],
        queryFn: ({ signal }) => api<Overview>('/overview', { signal }),
    });
}
