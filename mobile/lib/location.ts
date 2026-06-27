import * as Location from 'expo-location';

export type Coords = { latitude: number; longitude: number; accuracy: number | null };

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
    };
  } catch {
    return null;
  }
}
