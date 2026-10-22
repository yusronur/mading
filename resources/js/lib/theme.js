// Menerapkan tema dari props Inertia ke <html> tanpa memuat ulang halaman.
export function applyTheme(theme) {
    if (!theme || typeof document === 'undefined') return;
    const root = document.documentElement;
    root.dataset.mode = theme.mode || 'system';
    root.dataset.fontScale = String(theme.font_scale || 100);
    root.dataset.reduceMotion = theme.reduce_motion ? '1' : '0';

    let style = document.getElementById('theme-vars');
    if (!style) {
        style = document.createElement('style');
        style.id = 'theme-vars';
        document.head.appendChild(style);
    }
    if (style.textContent !== theme.css) style.textContent = theme.css;

    const bg = getComputedStyle(root).getPropertyValue('--m-bg').trim();
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta && bg) meta.setAttribute('content', bg);
}

export function timeAgo(iso) {
    if (!iso) return '';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'baru saja';
    if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} jam lalu`;
    if (diff < 604800) return `${Math.floor(diff / 86400)} hari lalu`;
    return new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}
