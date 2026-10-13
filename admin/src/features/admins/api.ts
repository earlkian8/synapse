import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, query } from '@/lib/api';
import type {
    Admin,
    AdminRole,
    ListFilters,
    Option,
    Paginated,
} from '@/lib/types';

export type AdminStats = {
    total: number;
    active: number;
    super_admins: number;
    without_two_factor: number;
};

export type AdminParams = {
    search: string;
    status: string;
    role: string;
    sort: string;
    direction: string;
    per_page: number;
    page: number;
};

export type AdminsPage = Paginated<
    Admin,
    AdminStats,
    ListFilters & { role: string }
> & {
    roles: Option[];
};

export type AdminInput = { name: string; email: string; role: AdminRole };

export function useAdmins(params: AdminParams) {
    return useQuery({
        queryKey: ['admins', params],
        queryFn: ({ signal }) =>
            api<AdminsPage>(`/admins${query(params)}`, { signal }),
        placeholderData: keepPreviousData,
    });
}

export const createAdmin = (body: AdminInput) =>
    api<{ message: string; admin: Admin; temporary_password: string }>(
        '/admins',
        {
            method: 'POST',
            body,
        },
    );

export const updateAdmin = (id: number, body: AdminInput) =>
    api<{ message: string; admin: Admin }>(`/admins/${id}`, {
        method: 'PATCH',
        body,
    });

export const setAdminActive = (id: number, isActive: boolean) =>
    api<{ message: string; admin: Admin }>(`/admins/${id}/status`, {
        method: 'PATCH',
        body: { is_active: isActive },
    });

export const resetAdminPassword = (id: number) =>
    api<{ message: string; admin: Admin; temporary_password: string }>(
        `/admins/${id}/reset-password`,
        { method: 'POST' },
    );

export const resetAdminTwoFactor = (id: number) =>
    api<{ message: string; admin: Admin }>(`/admins/${id}/reset-two-factor`, {
        method: 'POST',
    });

export const deleteAdmin = (id: number) =>
    api<{ message: string }>(`/admins/${id}`, { method: 'DELETE' });
