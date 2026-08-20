import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    { ignores: ['public/build/**', 'node_modules/**', 'vendor/**'] },
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}', 'resources/pwa/**/*.js', 'scripts/**/*.mjs', 'vite.config.js', 'vitest.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: { ...globals.browser, ...globals.node, ...globals.serviceworker },
            parserOptions: { ecmaFeatures: { jsx: true } },
        },
        plugins: { react, 'react-hooks': reactHooks },
        settings: { react: { version: 'detect' } },
        rules: {
            ...react.configs.recommended.rules,
            ...reactHooks.configs.recommended.rules,
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react-hooks/set-state-in-effect': 'off',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
        },
    },
];
