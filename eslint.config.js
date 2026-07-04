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
            // Restore the React Hooks lint coverage the codebase already relies
            // on (it carries `eslint-disable react-hooks/exhaustive-deps`
            // directives that, with the rule unregistered, made every such file
            // error with "rule not found"). Both rules are `warn` for now:
            // `rules-of-hooks` surfaces ~13 pre-existing conditional-hook
            // violations (hooks after an early return) in field/profile
            // components — genuine latent bugs tracked for a dedicated fix, after
            // which this should be promoted to `error`.
            'react-hooks/rules-of-hooks': 'warn',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        ignores: ['public/**', 'node_modules/**', 'resources/js/**/*.test.*'],
    },
];

