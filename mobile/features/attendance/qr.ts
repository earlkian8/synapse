/**
 * What a QR station's screen shows (QrCodes, ADR 0103): `synapse://qr/SQ1.…` at
 * a station that takes the app only, or `https://<server>/q/SQ1.…` at one that
 * takes any phone. The app's scanner reads either; the server judges the rest.
 */
const SCHEME = 'synapse://qr/';
const PREFIX = 'SQ1.';
const WEB = /^https?:\/\/[^/\s]+\/q\/(SQ1\.[A-Za-z0-9._-]+)$/;

/** The bare code inside whatever the camera read, or null when it isn't one. */
export function stationCodeOf(data: string): string | null {
  const read = data.trim();
  const code = read.startsWith(SCHEME)
    ? read.slice(SCHEME.length)
    : (WEB.exec(read)?.[1] ?? read);

  return code.startsWith(PREFIX) && code.length < 120 ? code : null;
}

/**
 * Whether something the camera read is a station's code at all, so a poster's
 * QR code across the room doesn't send anything.
 */
export function looksLikeStationCode(data: string): boolean {
  return stationCodeOf(data) !== null;
}
