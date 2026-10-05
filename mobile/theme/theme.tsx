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
import { Appearance, useColorScheme as useSystemScheme } from 'react-native';

import { readableOn } from './color';
import {
  fonts,
  radius,
  schemes,
  spacing,
  squircle,
  status,
  typography,
  type ColorScheme,
} from './tokens';

export type ThemeMode = 'light' | 'dark' | 'system';

type ThemeValue = {
  mode: ThemeMode;
  scheme: 'light' | 'dark';
  colors: ColorScheme;
  spacing: typeof spacing;
  radius: typeof radius;
  squircle: typeof squircle;
  fonts: typeof fonts;
  typography: typeof typography;
  status: typeof status;
  /**
   * The version of a colour that can legibly carry text — or be the only thing marking
   * a state — on `surface` (the card, unless you say otherwise). Status tones and the
   * colours HR picked for leave and award types all pass through here before they are
   * painted, so nothing lands at 2.5:1 on white. See ./color.ts.
   */
  readable: (color: string, surface?: string, ratio?: number) => string;
  setMode: (mode: ThemeMode) => void;
};

const STORAGE_KEY = 'synapse.theme-mode';

const ThemeContext = createContext<ThemeValue | null>(null);

/**
 * Hands the chosen scheme to the platform too, so what the app doesn't draw itself
 * (the keyboard, alerts, action sheets, the date picker) matches what it does. With
 * 'system' the override is lifted and the phone's own setting shows through again.
 */
function applyToPlatform(mode: ThemeMode) {
  Appearance.setColorScheme(mode === 'system' ? 'unspecified' : mode);
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  // RN reports 'unspecified' as well as null when the platform has no preference.
  const system: 'light' | 'dark' = useSystemScheme() === 'dark' ? 'dark' : 'light';
  const [mode, setModeState] = useState<ThemeMode>('system');

  useEffect(() => {
    AsyncStorage.getItem(STORAGE_KEY).then((value) => {
      if (value === 'light' || value === 'dark' || value === 'system') {
        setModeState(value);
        applyToPlatform(value);
      }
    });
  }, []);

  const setMode = useCallback((next: ThemeMode) => {
    setModeState(next);
    applyToPlatform(next);
    void AsyncStorage.setItem(STORAGE_KEY, next);
  }, []);

  const scheme: 'light' | 'dark' = mode === 'system' ? system : mode;

  const value = useMemo<ThemeValue>(() => {
    const colors = schemes[scheme];

    return {
      mode,
      scheme,
      colors,
      spacing,
      radius,
      squircle,
      fonts,
      typography,
      status,
      readable: (color, surface = colors.card, ratio = 4.5) => readableOn(color, surface, ratio),
      setMode,
    };
  }, [mode, scheme, setMode]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

/**
 * Re-provides the theme in a fixed scheme for one subtree.
 *
 * The entry screens — splash, sign-in, register, workspace picker — are white whatever
 * the phone's appearance setting says (see `EntryScreen`). Without this, their fields
 * and labels resolved against the *dark* scheme on a phone set to dark — pale grey type
 * on white, around 2:1. The ground declares the surface it actually is, and everything
 * inside it resolves against that.
 */
export function FixedScheme({
  scheme,
  children,
}: {
  scheme: 'light' | 'dark';
  children: ReactNode;
}) {
  const parent = useTheme();

  const value = useMemo<ThemeValue>(() => {
    const colors = schemes[scheme];

    return {
      ...parent,
      scheme,
      colors,
      readable: (color, surface = colors.card, ratio = 4.5) => readableOn(color, surface, ratio),
    };
  }, [parent, scheme]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext);

  if (!ctx) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }

  return ctx;
}
