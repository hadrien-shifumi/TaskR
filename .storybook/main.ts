import type { StorybookConfig } from '@storybook/react-vite';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
import { mergeAlias } from 'vite';

const fromStorybookDir = (path: string): string => fileURLToPath(new URL(path, import.meta.url));

const config: StorybookConfig = {
    stories: ['../resources/js/**/*.stories.@(ts|tsx)'],
    addons: ['@storybook/addon-docs'],
    staticDirs: ['../public'],
    framework: {
        name: '@storybook/react-vite',
        options: {},
    },
    viteFinal: (config) => {
        // Mirror the app pipeline (vite.config.js): the Tailwind v4 plugin
        // compiles resources/css/app.css, which carries every design token.
        // laravel-vite-plugin is deliberately omitted — Storybook builds a
        // standalone static site with no Laravel backend behind it.
        config.plugins = [...(config.plugins ?? []), tailwindcss()];

        config.resolve = {
            ...config.resolve,
            alias: mergeAlias(config.resolve?.alias, {
                // Same '@' -> resources/js prefix alias as the app build. The
                // Inertia mock is an exact-module alias: '@inertiajs/react'
                // never starts with '@/', so the two entries cannot shadow
                // each other.
                '@inertiajs/react': fromStorybookDir('./mocks/inertia-react.tsx'),
                '@': fromStorybookDir('../resources/js'),
            }),
        };

        return config;
    },
};

export default config;
