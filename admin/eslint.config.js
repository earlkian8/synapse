import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import { defineConfig, globalIgnores } from 'eslint/config';

export default defineConfig([
    globalIgnores(['dist']),
    {
        files: ['**/*.{ts,tsx}'],
        extends: [
            js.configs.recommended,
            tseslint.configs.recommended,
            reactHooks.configs.flat.recommended,
            reactRefresh.configs.vite,
        ],
        languageOptions: {
            globals: globals.browser,
        },
    },
    {
        // shadcn/ui primitives export their variants beside the component, and
        // the list kit (copied from the ERP) its helpers beside its tables.
        files: [
            'src/components/ui/**/*.tsx',
            'src/components/data-table/**/*.tsx',
        ],
        rules: { 'react-refresh/only-export-components': 'off' },
    },
]);
