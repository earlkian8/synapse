/**
 * Auth state for the app. The Sanctum personal-access token lives in
 * SecureStore; on boot we restore it, wire it into the {@link api} client, and
 * hydrate the signed-in user from `/me`. Exposes `login` / `logout` and the
 * current user + linked employee to the whole tree.
 */
import * as SecureStore from 'expo-secure-store';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

import { api, setTokenProvider } from '@/lib/api';
import type { AuthUser } from '@/types/api';

const TOKEN_KEY = 'synapse.token';

type AuthValue = {
  /** True until the stored token has been restored and (if present) validated. */
  isLoading: boolean;
  isAuthenticated: boolean;
  user: AuthUser | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Keep the api client's token getter pointed at the latest token.
  useEffect(() => {
    setTokenProvider(() => token);
  }, [token]);

  const hydrate = useCallback(async (activeToken: string) => {
    setTokenProvider(() => activeToken);
    const { user: me } = await api.get<{ user: AuthUser }>('/me');
    setUser(me);
  }, []);

  // On first mount, restore any stored session.
  useEffect(() => {
    (async () => {
      try {
        const stored = await SecureStore.getItemAsync(TOKEN_KEY);

        if (stored) {
          setToken(stored);
          await hydrate(stored);
        }
      } catch {
        // Token invalid/expired or server unreachable — drop to signed-out.
        await SecureStore.deleteItemAsync(TOKEN_KEY);
        setToken(null);
        setUser(null);
      } finally {
        setIsLoading(false);
      }
    })();
  }, [hydrate]);

  const login = useCallback(
    async (email: string, password: string) => {
      const result = await api.post<{ token: string; user: AuthUser }>('/auth/login', {
        email,
        password,
        device_name: 'SYNAPSE Mobile',
      });

      await SecureStore.setItemAsync(TOKEN_KEY, result.token);
      setToken(result.token);
      setUser(result.user);
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Best effort — clear the local session regardless.
    }

    await SecureStore.deleteItemAsync(TOKEN_KEY);
    setToken(null);
    setUser(null);
  }, []);

  const refresh = useCallback(async () => {
    if (!token) return;
    const { user: me } = await api.get<{ user: AuthUser }>('/me');
    setUser(me);
  }, [token]);

  const value = useMemo<AuthValue>(
    () => ({
      isLoading,
      isAuthenticated: token !== null && user !== null,
      user,
      login,
      logout,
      refresh,
    }),
    [isLoading, token, user, login, logout, refresh],
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
