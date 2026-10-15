import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob(['./Pages/**/*.tsx', '!./Pages/**/components/**/*.tsx']);

createInertiaApp({
    title: (title) => `${title} - Taskr`,
    resolve: (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
