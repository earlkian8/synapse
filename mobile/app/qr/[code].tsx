import { Redirect, useLocalSearchParams } from 'expo-router';

/**
 * The link a QR station's code is (ADR 0103), opened by the phone's own camera:
 * `synapse://qr/SQ1.…` lands here and goes on to the scanner with the code, which
 * checks it at once.
 */
export default function StationLink() {
  const { code } = useLocalSearchParams<{ code: string }>();

  return <Redirect href={{ pathname: '/attendance/scan', params: { code: `synapse://qr/${code ?? ''}` } }} />;
}
