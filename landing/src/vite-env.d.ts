/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_ERP_URL?: string;
    readonly VITE_APK_URL?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
