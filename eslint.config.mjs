// ESLint flat config. The `.mjs` extension marks it as an ES module: the
// package has no `"type": "module"` (postcss.config.js is CommonJS), so a
// `.js` config made Node reparse it as ESM, with a warning, on every run.
// CI runs `npm run lint -- --max-warnings=0`, so a warning fails the build.
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
            // React Hooks lint coverage (the codebase carries
            // `eslint-disable react-hooks/exhaustive-deps` directives, which
            // error with "rule not found" when the rule is unregistered). A
            // hook called after an early return crashes the component the
            // first time the return is taken or skipped, so `rules-of-hooks`
            // is an error.
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        ignores: ['public/**', 'node_modules/**', 'resources/js/**/*.test.*'],
    },
];

