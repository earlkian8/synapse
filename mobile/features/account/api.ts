import { api } from '@/lib/api';
import type { Account } from '@/types/api';

export type AccountDetails = {
  first_name: string;
  middle_name: string | null;
  last_name: string;
  email: string;
  /** Asked for only when the email address changes. */
  current_password?: string;
};

/**
 * One's own sign-in account (ADR 0082): the name, email address and password.
 * The 201 record beside it is HR's, and read-only in the app (`profileApi`).
 */
export const accountApi = {
  show: () => api.get<{ data: Account }>('/account'),
  update: (details: AccountDetails) =>
    api.patch<{ data: Account; email_changed: boolean; message: string }>('/account', details),
  /** Signs the app out on every other phone; this one stays signed in. */
  changePassword: (input: { current_password: string; password: string; password_confirmation: string }) =>
    api.put<{ data: Account; message: string }>('/account/password', input),
  /** Fortify's reset link, mailed. Answers the same whether or not the address has an account. */
  forgotPassword: (email: string) =>
    api.post<{ message: string; expires_minutes: number }>('/auth/forgot-password', { email }),
};
