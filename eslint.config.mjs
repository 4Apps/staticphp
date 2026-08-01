import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import importX from 'eslint-plugin-import-x';
import compat from 'eslint-plugin-compat';
import globals from 'globals';

/**
 * Flat config, replacing .eslintrc.json.
 *
 * The old config had @typescript-eslint commented out of both `plugins` and
 * `parserOptions.project`, so .ts files were parsed as plain JavaScript and effectively
 * went unlinted. Its import resolver also pointed at webpack.config.js, which no longer
 * exists. TypeScript is linted properly here, with the resolver reading tsconfig.json so
 * the `base/*` path alias resolves.
 */
export default tseslint.config(
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'build/**',
            'dist/**',
            'src/*/Public/assets/dist/**',
            'src/*/Cache/**',
            'presets/**',
            '*.config.js',
            '*.config.mjs',
        ],
    },

    js.configs.recommended,
    ...tseslint.configs.recommended,
    compat.configs['flat/recommended'],

    {
        files: ['**/*.{js,jsx,ts,tsx}'],
        plugins: {
            'import-x': importX,
        },
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Injected by the bundler's DefinePlugin - see assets/src/globals.d.ts
                APP_ENV: 'readonly',
                APP_VERSION: 'readonly',
                APP_GIT_COMMIT_HASH: 'readonly',
                APP_GIT_COMMIT_DATE: 'readonly',
                APP_NAME: 'readonly',
                // Set by the framework's own templates
                BASE_URL: 'readonly',
                BASE_URI: 'readonly',
                translateStrings: 'readonly',
            },
        },
        settings: {
            'import-x/resolver': {
                typescript: {
                    // One config per application - the base/* alias points at the
                    // importing application's own copy, so a single root config would
                    // resolve every application against the first one's
                    project: ['./src/*/tsconfig.json'],
                },
            },
        },
        rules: {
            'import-x/no-unresolved': 'error',
            'no-new': 'off',
            indent: ['error', 4, { SwitchCase: 1 }],
        },
    },

    {
        files: ['**/*.{ts,tsx}'],
        languageOptions: {
            parserOptions: {
                // One project per application, matched by glob. A single root config
                // cannot serve them: base/* has to resolve to the importing application's
                // own copy, and tsconfig paths are resolved once, globally.
                project: ['./src/*/tsconfig.json'],
                tsconfigRootDir: import.meta.dirname,
            },
        },
    },
);
