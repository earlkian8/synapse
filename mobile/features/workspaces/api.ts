/**
 * The two ways into a company (ADR 0026): a code the company published, or an
 * invitation addressed to one roster line.
 *
 * Both have a `preview` step that names the company before anything is committed
 * to — typing seven characters off a whiteboard is easy to get wrong, and joining
 * the wrong employer is not a mistake worth making silently.
 */
import { api } from '@/lib/api';
import type { AuthOrganization, AuthUser, Invitation, JoinOutcome } from '@/types/api';

/** Look up the company behind a join code without joining it. */
export function previewWorkspace(code: string) {
  return api.post<{ organization: AuthOrganization; already_member: boolean }>(
    '/workspaces/preview',
    { code },
  );
}

/**
 * Redeem a join code. `admitted` comes back with a new session bound to the
 * company; `pending` leaves the session alone and adds a pending request.
 */
export function joinWorkspace(code: string) {
  return api.post<{
    status: JoinOutcome;
    message: string;
    token?: string;
    user: AuthUser;
  }>('/workspaces/join', { code });
}

/** Invitations addressed to this account's email address. */
export function fetchInvitations() {
  return api.get<{ data: Invitation[] }>('/invitations');
}

/** Look up an invitation by its code without redeeming it. */
export function previewInvitation(code: string) {
  return api.post<{ invitation: Invitation }>('/invitations/preview', { code });
}

/** Redeem an invitation — always returns a session bound to the new company. */
export function acceptInvitation(code: string) {
  return api.post<{ message: string; token: string; user: AuthUser }>('/invitations/accept', {
    code,
  });
}

/** Turn down an invitation addressed to this account. */
export function declineInvitation(id: number) {
  return api.delete<{ message: string }>(`/invitations/${id}`);
}
