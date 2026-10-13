import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => `${title} - Taskr`,
    resolve: (name) => import(`./Pages/${name}.tsx`),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
