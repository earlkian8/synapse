import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, query } from '@/lib/api';
import type { ListFilters, Option, Paginated } from '@/lib/types';

export type ActivityEntry = {
    id: number;
    event: string;
    event_label: string;
    description: string;
    actor: {
        id: number | null;
        name: string | null;
        email: string | null;
    } | null;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    properties: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
};

export type ActivityStats = {
    total: number;
    today: number;
    failed_sign_ins_7d: number;
    admins_active_7d: number;
};

export type ActivityParams = {
    search: string;
    event: string;
    admin_id: string;
    sort: string;
    direction: string;
    per_page: number;
    page: number;
};

export type ActivityPage = Paginated<
    ActivityEntry,
    ActivityStats,
    ListFilters & { event: string; admin_id: number | null }
> & {
    events: Option[];
    admins: { id: number; name: string; email: string }[];
};

export function useActivity(params: ActivityParams) {
    return useQuery({
        queryKey: ['activity', params],
        queryFn: ({ signal }) =>
            api<ActivityPage>(
                `/activity${query({ ...params, event: params.event === 'all' ? '' : params.event, admin_id: params.admin_id === 'all' ? '' : params.admin_id })}`,
                { signal },
            ),
        placeholderData: keepPreviousData,
    });
}
