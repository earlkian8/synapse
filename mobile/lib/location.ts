import * as Location from 'expo-location';

export type Coords = {
  latitude: number;
  longitude: number;
  accuracy: number | null;
  /** Android's word that the position came from a location-faking app. */
  mocked?: boolean;
};

/**
 * Best-effort current position for a DTR punch. Returns null when permission is
 * denied or the fix fails, so clocking still works without location (the server
 * accepts a punch with no coordinates).
 */
export async function getCurrentCoords(): Promise<Coords | null> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();

    if (status !== 'granted') {
      return null;
    }

    const position = await Location.getCurrentPositionAsync({
      accuracy: Location.Accuracy.Balanced,
    });

    return {
      latitude: position.coords.latitude,
      longitude: position.coords.longitude,
      accuracy: position.coords.accuracy ?? null,
      mocked: position.mocked ?? false,
    };
  } catch {
    return null;
  }
}

export type PreciseFix = { coords: Coords | null; denied: boolean };

/**
 * A fresh, precise position for a QR scan (ADR 0103), which the server judges
 * against the station's site and refuses past 100 m of doubt. Never a cached
 * fix: a phone carried in from the car park must not pass as at the desk.
 */
export async function getPreciseCoords(): Promise<PreciseFix> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();

    if (status !== 'granted') {
      return { coords: null, denied: true };
    }

    const position = await Location.getCurrentPositionAsync({
      accuracy: Location.Accuracy.High,
    });

    return {
      denied: false,
      coords: {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy ?? null,
        mocked: position.mocked ?? false,
      },
    };
  } catch {
    return { coords: null, denied: false };
  }
}
