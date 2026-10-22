// Konfeti ringan tanpa pustaka (LIV-06). Dilewati bila pengguna memilih kurangi animasi
// atau mematikan efek perayaan.
import { effects } from '@/lib/effects';

export function confetti({ duration = 2600, count = 140 } = {}) {
    if (!effects.confetti) return;
    const reduce = document.documentElement.dataset.reduceMotion === '1' || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) return;
    const c = document.createElement('canvas');
    c.setAttribute('aria-hidden', 'true');
    c.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:80';
    document.body.appendChild(c);
    const ctx = c.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const resize = () => { c.width = innerWidth * dpr; c.height = innerHeight * dpr; };
    resize();
    const colors = ['#ff6b81', '#2f6fed', '#ffbe2e', '#26c6a8', '#7c4ddb', '#ff8f3d', '#7cff6b'];
    const parts = Array.from({ length: count }, () => ({
        x: Math.random() * c.width, y: -Math.random() * c.height * 0.5,
        w: (6 + Math.random() * 6) * dpr, h: (8 + Math.random() * 8) * dpr,
        vx: (Math.random() - 0.5) * 3 * dpr, vy: (2 + Math.random() * 4) * dpr,
        r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.3, color: colors[(Math.random() * colors.length) | 0],
    }));
    const start = performance.now();
    const frame = (t) => {
        ctx.clearRect(0, 0, c.width, c.height);
        const fade = Math.max(0, 1 - (t - start - duration * 0.7) / (duration * 0.3));
        ctx.globalAlpha = Math.min(1, fade);
        for (const p of parts) {
            p.x += p.vx; p.y += p.vy; p.vy += 0.05 * dpr; p.r += p.vr;
            ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.fillStyle = p.color; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h); ctx.restore();
        }
        if (t - start < duration) requestAnimationFrame(frame);
        else c.remove();
    };
    requestAnimationFrame(frame);
}
