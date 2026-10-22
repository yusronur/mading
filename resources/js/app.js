import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import { setupPwa } from '@/lib/push';

setupPwa();

createInertiaApp({
    title: (title) => {
        const name = document.querySelector('meta[name="app-display-name"]')?.content || 'Mading';
        return title ? `${title} · ${name}` : name;
    },
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const page = pages[`./Pages/${name}.vue`];
        if (!page) throw new Error(`Halaman tidak ditemukan: ${name}`);
        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: { color: '#29E7FF', showSpinner: false },
});
