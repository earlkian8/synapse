/// <reference types="vite/client" />

interface ImportMetaEnv {
    /** The web ERP's origin; its API is at /api/admin. See .env.example. */
    readonly VITE_ERP_URL?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
