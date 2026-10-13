/**
 * Where the landing page sends people. Both come from the build's environment
 * (see .env.example) so a staging build can point at a staging ERP.
 */
const erpOrigin = (
    import.meta.env.VITE_ERP_URL ?? 'https://synapse-lzsdm.ondigitalocean.app'
).replace(/\/+$/, '');

export const links = {
    erp: erpOrigin,
    signIn: `${erpOrigin}/login`,
    register: `${erpOrigin}/register`,
    privacy: `${erpOrigin}/privacy`,
    terms: `${erpOrigin}/terms`,
    apk: import.meta.env.VITE_APK_URL ?? '/downloads/synapse.apk',
} as const;

/** The APK's address as a phone scanning the QR code needs it: absolute. */
export function absoluteApkUrl(): string {
    return new URL(links.apk, window.location.href).toString();
}

/** The app's version, as mobile/app.json has it. */
export const appVersion = '1.0.0';
