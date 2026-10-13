import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, query } from '@/lib/api';
import type { Paginated } from '@/lib/types';

export type Account = {
    id: number;
    full_name: string;
    email: string;
    is_active: boolean;
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    last_login_at: string | null;
    created_at: string | null;
    archived_at: string | null;
    memberships_count: number;
};

export type AccountDetail = Account & {
    password_changed_at: string | null;
    mobile_sessions: number;
    mobile_last_used_at: string | null;
    memberships: {
        id: number;
        name: string;
        archived: boolean;
        is_default: boolean;
        joined_at: string | null;
        roles: string[];
    }[];
};

export type AccountStats = {
    total: number;
    active_30d: number;
    unverified: number;
    inactive: number;
    no_workspace: number;
    mobile_sessions: number;
};

export type AccountParams = {
    search: string;
    status: string;
    sort: string;
    direction: string;
    per_page: number;
    page: number;
};

export function useAccounts(params: AccountParams) {
    return useQuery({
        queryKey: ['accounts', params],
        queryFn: ({ signal }) =>
            api<Paginated<Account, AccountStats>>(`/accounts${query(params)}`, {
                signal,
            }),
        placeholderData: keepPreviousData,
    });
}

export function useAccount(id: number | null) {
    return useQuery({
        queryKey: ['account', id],
        queryFn: ({ signal }) =>
            api<{ data: AccountDetail }>(`/accounts/${id}`, { signal }),
        enabled: id !== null,
    });
}

export function accountStatus(
    account: Account,
): 'archived' | 'inactive' | 'unverified' | 'active' {
    if (account.archived_at) {
        return 'archived';
    }

    if (!account.is_active) {
        return 'inactive';
    }

    return account.email_verified_at ? 'active' : 'unverified';
}
