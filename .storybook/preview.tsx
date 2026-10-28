import '../resources/css/app.css';

import type { Preview } from '@storybook/react';
import { useEffect } from 'react';

import i18n, { initI18n } from '../resources/js/i18n';

// The ziggy `route()` global is normally injected by the backend blade layout
// (`@routes` in resources/views/app.blade.php); stories have no backend, so
// stub it before anything renders.
(globalThis as any).route = (name: string, _params?: unknown, _absolute?: boolean): string => `#${name}`;

// Bundle the translations once; the locale toolbar below switches languages.
initI18n('en');

const preview: Preview = {
    globalTypes: {
        locale: {
            name: 'Locale',
            description: 'Interface language',
            defaultValue: 'en',
            toolbar: {
                icon: 'globe',
                items: [
                    { value: 'en', title: 'English' },
                    { value: 'fr', title: 'Français' },
                ],
            },
        },
        theme: {
            name: 'Theme',
            description: 'Color scheme (light/dark design tokens)',
            defaultValue: 'light',
            toolbar: {
                icon: 'mirror',
                items: [
                    { value: 'light', title: 'Light' },
                    { value: 'dark', title: 'Dark' },
                ],
            },
        },
    },
    decorators: [
        (Story, { globals }) => {
            const locale = typeof globals.locale === 'string' ? globals.locale : 'en';

            useEffect(() => {
                void i18n.changeLanguage(locale);
            }, [locale]);

            return <Story />;
        },
        (Story, { globals }) => {
            const dark = globals.theme === 'dark';

            useEffect(() => {
                // app.css defines the dark tokens on `.dark` and scopes the
                // `dark:` variant with `@custom-variant dark (&:is(.dark *))`.
                document.documentElement.classList.toggle('dark', dark);
            }, [dark]);

            return <Story />;
        },
    ],
    parameters: {
        layout: 'padded',
        controls: { hideNoControlsWarning: true },
        options: {
            storySort: {
                order: ['UI', 'Components'],
            },
        },
    },
};

export default preview;
