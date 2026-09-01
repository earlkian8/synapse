/**
 * Theme context: resolves the active colour scheme (light/dark/system),
 * persists the user's preference, and exposes design tokens to the whole app via
 * {@link useTheme}.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { useColorScheme as useSystemScheme } from 'react-native';

import { radius, schemes, spacing, status, typography, type ColorScheme } from './tokens';

export type ThemeMode = 'light' | 'dark' | 'system';

type ThemeValue = {
  mode: ThemeMode;
  scheme: 'light' | 'dark';
  colors: ColorScheme;
  spacing: typeof spacing;
  radius: typeof radius;
  typography: typeof typography;
  status: typeof status;
  setMode: (mode: ThemeMode) => void;
};

const STORAGE_KEY = 'synapse.theme-mode';

const ThemeContext = createContext<ThemeValue | null>(null);

export function ThemeProvider({ children }: { children: ReactNode }) {
  const system = useSystemScheme() ?? 'light';
  const [mode, setModeState] = useState<ThemeMode>('system');

  useEffect(() => {
    AsyncStorage.getItem(STORAGE_KEY).then((value) => {
      if (value === 'light' || value === 'dark' || value === 'system') {
        setModeState(value);
      }
    });
  }, []);

  const setMode = useCallback((next: ThemeMode) => {
    setModeState(next);
    void AsyncStorage.setItem(STORAGE_KEY, next);
  }, []);

  const scheme: 'light' | 'dark' = mode === 'system' ? system : mode;

  const value = useMemo<ThemeValue>(
    () => ({
      mode,
      scheme,
      colors: schemes[scheme],
      spacing,
      radius,
      typography,
      status,
      setMode,
    }),
    [mode, scheme, setMode],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext);

  if (!ctx) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }

  return ctx;
}
