/**
 * Where the portal's API lives: the web ERP named by VITE_ERP_URL at build time
 * (.env — development by default, production commented under it).
 */
export const ERP_URL = (
    import.meta.env.VITE_ERP_URL ?? 'http://localhost:8000'
).replace(/\/+$/, '');

export const API_ROOT = `${ERP_URL}/api/admin`;
