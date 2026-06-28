/**
 * Auth state for the app.
 *
 * A user is a single identity (one login) that can belong to several companies
 * (ADR 0023). The Sanctum token in SecureStore is bound to one **active**
 * organisation; switching companies asks the server for a fresh token bound to the
 * chosen org (`/auth/switch`) and swaps it in — no re-entering credentials. The
 * signed-in user carries both the active `organization` and the full list of
 * `organizations` the switcher offers.
 *
 * On boot we restore the token and re-hydrate from `/me`.
 */
import * as SecureStore from 'expo-secure-store';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

import { setActiveWorkspaceId } from '@/lib/active-workspace';
import { api, setTokenProvider } from '@/lib/api';
import type { AuthOrganization, AuthUser } from '@/types/api';

const TOKEN_KEY = 'synapse.token';

type AuthValue = {
  /** True until the stored token has been restored and (if present) validated. */
  isLoading: boolean;
  isAuthenticated: boolean;
  user: AuthUser | null;
  /** The active organisation (company) this session is scoped to. */
  organization: AuthOrganization | null;
  /** Every organisation the identity belongs to. */
  organizations: AuthOrganization[];
  /**
   * Whether the user has settled on a workspace this session. False right after a
   * fresh login when the identity belongs to more than one company — the gate that
   * sends them through the workspace picker before the app shell.
   */
  hasEnteredWorkspace: boolean;
  login: (email: string, password: string) => Promise<void>;
  /** Switch the active company — fetches a token bound to it, no re-auth. */
  switchTo: (organizationId: number) => Promise<void>;
  /** Commit to a workspace from the picker (switches only if it isn't the active one). */
  enterWorkspace: (organizationId: number) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  // Returning (restored) sessions skip the picker; a fresh multi-company login flips
  // this off so the root navigator routes through it.
  const [hasEnteredWorkspace, setHasEnteredWorkspace] = useState(true);

  // The api client reads the token through this ref so we can point requests at a
  // token synchronously (e.g. while validating on boot).
  const tokenRef = useRef<string | null>(null);

  useEffect(() => {
    setTokenProvider(() => tokenRef.current);
  }, []);

  // Apply a new session (or clear it), persisting the token and publishing the
  // active workspace so tenant-scoped queries refetch.
  const apply = useCallback(async (nextToken: string | null, nextUser: AuthUser | null) => {
    tokenRef.current = nextToken;
    setToken(nextToken);
    setUser(nextUser);
    setActiveWorkspaceId(nextUser?.organization ? String(nextUser.organization.id) : null);

    if (nextToken) {
      await SecureStore.setItemAsync(TOKEN_KEY, nextToken);
    } else {
      await SecureStore.deleteItemAsync(TOKEN_KEY);
    }
  }, []);

  // On first mount, restore any stored session.
  useEffect(() => {
    (async () => {
      try {
        const stored = await SecureStore.getItemAsync(TOKEN_KEY);

        if (stored) {
          tokenRef.current = stored;
          const { user: me } = await api.get<{ user: AuthUser }>('/me');
          setToken(stored);
          setUser(me);
          setActiveWorkspaceId(me.organization ? String(me.organization.id) : null);
          // A restored session already has a bound workspace — don't re-prompt.
          setHasEnteredWorkspace(true);
        }
      } catch {
        // Token invalid/expired or server unreachable — drop to signed-out.
        await SecureStore.deleteItemAsync(TOKEN_KEY);
        tokenRef.current = null;
        setToken(null);
        setUser(null);
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const result = await api.post<{ token: string; user: AuthUser }>('/auth/login', {
        email,
        password,
        device_name: 'SYNAPSE Mobile',
      });

      await apply(result.token, result.user);
      // More than one company? Send them through the picker before the app shell.
      setHasEnteredWorkspace((result.user.organizations?.length ?? 0) <= 1);
    },
    [apply],
  );

  const switchTo = useCallback(
    async (organizationId: number) => {
      const result = await api.post<{ token: string; user: AuthUser }>('/auth/switch', {
        organization_id: organizationId,
      });

      await apply(result.token, result.user);
      setHasEnteredWorkspace(true);
    },
    [apply],
  );

  const enterWorkspace = useCallback(
    async (organizationId: number) => {
      // Already the active company — nothing to switch, just open it.
      if (organizationId === user?.organization?.id) {
        setHasEnteredWorkspace(true);
        return;
      }

      await switchTo(organizationId);
    },
    [switchTo, user],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Best effort — clear the local session regardless.
    }

    await apply(null, null);
    setHasEnteredWorkspace(true);
  }, [apply]);

  const refresh = useCallback(async () => {
    if (!tokenRef.current) return;
    const { user: me } = await api.get<{ user: AuthUser }>('/me');
    setUser(me);
    setActiveWorkspaceId(me.organization ? String(me.organization.id) : null);
  }, []);

  const value = useMemo<AuthValue>(
    () => ({
      isLoading,
      isAuthenticated: token !== null && user !== null,
      user,
      organization: user?.organization ?? null,
      organizations: user?.organizations ?? ([] as AuthOrganization[]),
      hasEnteredWorkspace,
      login,
      switchTo,
      enterWorkspace,
      logout,
      refresh,
    }),
    [isLoading, token, user, hasEnteredWorkspace, login, switchTo, enterWorkspace, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext);

  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }

  return ctx;
}
