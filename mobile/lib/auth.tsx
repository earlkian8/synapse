/**
 * Auth state for the app — now multi-workspace. A person employed by more than
 * one company has one account per company (ADR 0005), so we keep every signed-in
 * session and let the user switch between them instantly without re-authenticating.
 *
 * Sessions live in {@link sessions} (tokens in SecureStore, profiles in
 * AsyncStorage). The active session's token is what the {@link api} client sends;
 * switching workspaces just re-points that token and swaps the visible user. On
 * boot we restore the saved sessions (migrating any pre-multi-account token) and
 * revalidate the active one against `/me`.
 */
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
import { ApiError, api, setTokenProvider } from '@/lib/api';
import {
  readSessions,
  sessionId,
  takeLegacyToken,
  writeSessions,
  type Session,
} from '@/lib/sessions';
import type { AuthOrganization, AuthUser } from '@/types/api';

type AuthValue = {
  /** True until saved sessions have been restored and the active one validated. */
  isLoading: boolean;
  isAuthenticated: boolean;
  /** The active workspace's user, or null when signed out. */
  user: AuthUser | null;
  /** The active workspace's organisation (company). */
  organization: AuthOrganization | null;
  /** Every signed-in workspace, in the order they were added. */
  sessions: Session[];
  activeId: string | null;
  /** Authenticate a new (or re-authenticate an existing) workspace and make it active. */
  signIn: (email: string, password: string) => Promise<void>;
  /** Switch the active workspace — no network round-trip, no re-entry of credentials. */
  switchTo: (id: string) => Promise<void>;
  /** Sign out one workspace (defaults to the active one), falling back to another if any remain. */
  signOut: (id?: string) => Promise<void>;
  /** Re-pull the active workspace's profile from the server. */
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [sessions, setSessions] = useState<Session[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // The api client reads the active token through this ref. Keeping it in a ref
  // (rather than only in state) lets us point requests at a specific token
  // synchronously — needed when validating one session mid-boot or mid-switch.
  const tokenRef = useRef<string | null>(null);
  // A live mirror of sessions so async handlers never act on a stale closure.
  const sessionsRef = useRef<Session[]>([]);

  useEffect(() => {
    setTokenProvider(() => tokenRef.current);
  }, []);

  useEffect(() => {
    sessionsRef.current = sessions;
  }, [sessions]);

  // Publish the active workspace so tenant-scoped queries refetch on a switch.
  useEffect(() => {
    setActiveWorkspaceId(activeId);
  }, [activeId]);

  const apply = useCallback(async (next: Session[], nextActive: string | null) => {
    setSessions(next);
    setActiveId(nextActive);
    tokenRef.current = next.find((s) => s.id === nextActive)?.token ?? null;
    await writeSessions(next, nextActive);
  }, []);

  // Restore saved sessions on first mount (and migrate a legacy single token).
  useEffect(() => {
    (async () => {
      try {
        let { sessions: restored, activeId: restoredActive } = await readSessions();

        const legacy = await takeLegacyToken();

        if (legacy && restored.length === 0) {
          tokenRef.current = legacy;
          const { user } = await api.get<{ user: AuthUser }>('/me');
          const session: Session = { id: sessionId(user), token: legacy, user };
          restored = [session];
          restoredActive = session.id;
          await writeSessions(restored, restoredActive);
        }

        setSessions(restored);
        setActiveId(restoredActive);
        sessionsRef.current = restored;
        tokenRef.current = restored.find((s) => s.id === restoredActive)?.token ?? null;

        // Revalidate the active workspace; drop it if the token was revoked.
        if (tokenRef.current) {
          try {
            const { user } = await api.get<{ user: AuthUser }>('/me');
            const next = restored.map((s) => (s.id === restoredActive ? { ...s, user } : s));
            setSessions(next);
            sessionsRef.current = next;
            await writeSessions(next, restoredActive);
          } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
              const next = restored.filter((s) => s.id !== restoredActive);
              const nextActive = next[0]?.id ?? null;
              setSessions(next);
              setActiveId(nextActive);
              sessionsRef.current = next;
              tokenRef.current = next.find((s) => s.id === nextActive)?.token ?? null;
              await writeSessions(next, nextActive);
            }
          }
        }
      } catch {
        // Storage unreadable — start signed out rather than wedging the app.
        tokenRef.current = null;
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const result = await api.post<{ token: string; user: AuthUser }>('/auth/login', {
        email,
        password,
        device_name: 'SYNAPSE Mobile',
      });

      const session: Session = { id: sessionId(result.user), token: result.token, user: result.user };
      // Re-authenticating an already-saved workspace replaces it (the old token stays
      // valid server-side but is now orphaned; revoking it would need its plaintext).
      const next = [...sessionsRef.current.filter((s) => s.id !== session.id), session];

      await apply(next, session.id);
    },
    [apply],
  );

  const switchTo = useCallback(
    async (id: string) => {
      const target = sessionsRef.current.find((s) => s.id === id);
      if (!target || id === activeId) return;

      setActiveId(id);
      tokenRef.current = target.token;
      await writeSessions(sessionsRef.current, id);

      // Freshen the switched-in profile in the background; ignore transient failures.
      try {
        const { user } = await api.get<{ user: AuthUser }>('/me');
        const next = sessionsRef.current.map((s) => (s.id === id ? { ...s, user } : s));
        setSessions(next);
        sessionsRef.current = next;
        await writeSessions(next, id);
      } catch {
        // Keep the cached profile; a later refresh will catch up.
      }
    },
    [activeId],
  );

  const signOut = useCallback(
    async (id?: string) => {
      const targetId = id ?? activeId;
      if (!targetId) return;

      const target = sessionsRef.current.find((s) => s.id === targetId);

      // Revoke that workspace's token server-side, using it for this one request.
      if (target) {
        const previous = tokenRef.current;
        tokenRef.current = target.token;
        try {
          await api.post('/auth/logout');
        } catch {
          // Best effort — clear locally regardless.
        }
        tokenRef.current = previous;
      }

      const next = sessionsRef.current.filter((s) => s.id !== targetId);
      const nextActive = targetId === activeId ? next[0]?.id ?? null : activeId;

      await apply(next, nextActive);
    },
    [activeId, apply],
  );

  const refresh = useCallback(async () => {
    if (!activeId) return;
    const { user } = await api.get<{ user: AuthUser }>('/me');
    const next = sessionsRef.current.map((s) => (s.id === activeId ? { ...s, user } : s));
    setSessions(next);
    sessionsRef.current = next;
    await writeSessions(next, activeId);
  }, [activeId]);

  const activeSession = sessions.find((s) => s.id === activeId) ?? null;

  const value = useMemo<AuthValue>(
    () => ({
      isLoading,
      isAuthenticated: activeSession !== null,
      user: activeSession?.user ?? null,
      organization: activeSession?.user.organization ?? null,
      sessions,
      activeId,
      signIn,
      switchTo,
      signOut,
      refresh,
    }),
    [isLoading, activeSession, sessions, activeId, signIn, switchTo, signOut, refresh],
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
