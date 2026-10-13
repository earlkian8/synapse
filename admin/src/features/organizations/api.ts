import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, query } from '@/lib/api';
import type { Paginated } from '@/lib/types';

export type Organization = {
    id: number;
    name: string;
    legal_name: string | null;
    slug: string | null;
    email: string | null;
    logo_url: string | null;
    initials: string;
    timezone: string | null;
    setup_completed_at: string | null;
    created_at: string | null;
    archived_at: string | null;
    members_count: number;
    employees_count: number;
    last_activity_at: string | null;
};

export type OrganizationDetail = Organization & {
    phone: string | null;
    address: string | null;
    departments_count: number;
    pending_join_requests: number;
    activity_30d: number;
    owners: {
        id: number;
        full_name: string;
        email: string;
        last_login_at: string | null;
    }[];
    members: {
        id: number;
        full_name: string;
        email: string;
        is_active: boolean;
        joined_at: string | null;
        roles: string[];
    }[];
};

export type OrganizationStats = {
    total: number;
    ready: number;
    in_setup: number;
    archived: number;
    this_month: number;
};

export type OrganizationParams = {
    search: string;
    status: string;
    sort: string;
    direction: string;
    per_page: number;
    page: number;
};

export function useOrganizations(params: OrganizationParams) {
    return useQuery({
        queryKey: ['organizations', params],
        queryFn: ({ signal }) =>
            api<Paginated<Organization, OrganizationStats>>(
                `/organizations${query(params)}`,
                { signal },
            ),
        placeholderData: keepPreviousData,
    });
}

export function useOrganization(id: number | null) {
    return useQuery({
        queryKey: ['organization', id],
        queryFn: ({ signal }) =>
            api<{ data: OrganizationDetail }>(`/organizations/${id}`, {
                signal,
            }),
        enabled: id !== null,
    });
}

/** Where an organisation stands, in the words the badges use. */
export function organizationStatus(
    organization: Organization,
): 'archived' | 'ready' | 'setup' {
    if (organization.archived_at) {
        return 'archived';
    }

    return organization.setup_completed_at ? 'ready' : 'setup';
}
