import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import i18n, { initI18n } from './i18n';

const pages = import.meta.glob<{ default: ComponentType }>(['./Pages/**/*.tsx', '!./Pages/**/components/**/*.tsx']);

createInertiaApp({
    title: (title) => `${title} - Taskr`,
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        const module = await page();

        return module.default;
    },
    setup({ el, App, props }) {
        initI18n(
            typeof props.initialPage.props.locale === 'string'
                ? props.initialPage.props.locale
                : 'en',
        );

        // Keep the client language in sync with the server-resolved locale
        // (users.locale) on every Inertia response.
        router.on('success', (event) => {
            const locale = (event.detail.page.props as Record<string, unknown>).locale;

            if (typeof locale === 'string' && locale !== i18n.language) {
                void i18n.changeLanguage(locale);
            }
        });

        createRoot(el).render(<App {...props} />);
    },
});
