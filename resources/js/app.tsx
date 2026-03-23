import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';


createInertiaApp({
    title: (title: string) => `${title} - ${appName}`,
    resolve: (name: string) => {
        return resolvePageComponent(
            [`./Pages/${name}.tsx`],
            import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, () => Promise<{ default: React.ComponentType<any> }>>
        )
    },
    setup({ el, App, props }: { el: HTMLElement, App: React.ComponentType<any>, props: any }) {
        const root = createRoot(el);
        root.render(
            <App {...props} />
        );
    },
    progress: { color: '#4B5563' },
});
