import tsPlugin from '@typescript-eslint/eslint-plugin';
import tsParser from '@typescript-eslint/parser';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            parser: tsParser,
            parserOptions: {
                ecmaVersion: 'latest',
                sourceType: 'module',
                ecmaFeatures: { jsx: true },
            },
        },
        plugins: {
            '@typescript-eslint': tsPlugin,
            'react-hooks': reactHooks,
        },
        rules: {
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            // React Hooks lint coverage. `rules-of-hooks` is an error — the
            // pre-existing conditional-hook violations (hooks after an early
            // return in CodeField/IconField/MarkdownField/TrixField) have been
            // fixed. `exhaustive-deps` stays `warn` (advisory; several call sites
            // intentionally narrow their dep arrays via inline disable comments).
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        ignores: ['public/**', 'node_modules/**', 'resources/js/**/*.test.*'],
    },
];

