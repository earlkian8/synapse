import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { Admin } from '@/lib/types';

export type LoginResponse = {
    status: 'two_factor' | 'two_factor_setup';
    challenge: string;
    expires_in: number;
    setup?: { secret: string; otpauth_url: string };
};

export type SessionResponse = {
    token: string;
    expires_at: string;
    idle_minutes: number;
    admin: Admin;
};

export type MeResponse = {
    admin: Admin;
    expires_at: string | null;
    idle_minutes: number;
};

export const login = (email: string, password: string) =>
    api<LoginResponse>('/auth/login', {
        method: 'POST',
        body: { email, password },
    });

export const confirmCode = (challenge: string, code: string) =>
    api<SessionResponse>('/auth/two-factor', {
        method: 'POST',
        body: { challenge, code },
    });

export const logout = () =>
    api<{ message: string }>('/auth/logout', { method: 'POST' });

export const changePassword = (body: {
    current_password: string;
    password: string;
    password_confirmation: string;
}) =>
    api<{ message: string; admin: Admin }>('/account/password', {
        method: 'PUT',
        body,
    });

export const revokeOtherSessions = () =>
    api<{ message: string; ended: number }>('/account/sessions/revoke-others', {
        method: 'POST',
    });

/** The signed-in admin. Read once per session; kept fresh by the screens that change it. */
export function useMe() {
    const session = useSession();

    return useQuery({
        queryKey: ['me', session?.token],
        queryFn: ({ signal }) => api<MeResponse>('/me', { signal }),
        enabled: session !== null,
        staleTime: Infinity,
    });
}
