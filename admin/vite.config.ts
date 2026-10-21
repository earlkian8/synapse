import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import type { Plugin } from 'vite';

/**
 * The built portal's Content-Security-Policy, as a meta tag: scripts and styles
 * from the portal itself only, and requests to the ERP's API only. Left out of
 * `vite dev`, whose hot reload needs inline scripts and a websocket. Header-only
 * controls (frame-ancestors, HSTS) belong to the host; see README.md.
 */
function contentSecurityPolicy(erpUrl: string): Plugin {
    const erp = new URL(erpUrl).origin;
    const policy = [
        "default-src 'self'",
        "script-src 'self'",
        // Radix positions popovers with inline styles, which cannot carry a nonce.
        "style-src 'self' 'unsafe-inline'",
        // Organisation logos come from the ERP's storage, wherever it is hosted;
        // problem-report screenshots are fetched with the token and shown as blobs.
        `img-src 'self' data: blob: https: ${erp}`,
        "font-src 'self'",
        `connect-src ${erp}`,
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-src 'none'",
    ].join('; ');

    return {
        name: 'synapse-admin-csp',
        apply: 'build',
        transformIndexHtml: () => [
            {
                tag: 'meta',
                attrs: {
                    'http-equiv': 'Content-Security-Policy',
                    content: policy,
                },
                injectTo: 'head-prepend',
            },
        ],
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), 'VITE_');
    const erpUrl = env.VITE_ERP_URL || 'http://localhost:8000';

    return {
        plugins: [react(), tailwindcss(), contentSecurityPolicy(erpUrl)],
        resolve: {
            alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
        },
        server: { port: 5175, strictPort: true },
        preview: { port: 4175, strictPort: true },
        build: { sourcemap: false },
    };
});
