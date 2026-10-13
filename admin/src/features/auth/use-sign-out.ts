import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { endSession, getSession } from '@/lib/session';
import type { EndReason } from '@/lib/session';
import { logout } from './api';

/**
 * End the session here and on the server. The server call is best-effort: when
 * the session has already lapsed there, ending it here is all that is left.
 */
export function useSignOut() {
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    return async (reason: EndReason = 'signed_out') => {
        if (getSession()) {
            await logout().catch(() => undefined);
        }

        endSession(reason);
        queryClient.clear();
        navigate('/sign-in', { replace: true });
    };
}
