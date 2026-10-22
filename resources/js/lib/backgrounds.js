// Latar papan (BRD-01). Dipadukan dengan warna tema agar kartu tetap terbaca.
export const BACKGROUNDS = {
    none: { label: 'Polos', css: null },
    sunrise: { label: 'Fajar', css: 'linear-gradient(135deg, #ff9a8b33, #ffd86f33 50%, #a1c4fd33)' },
    ocean: { label: 'Laut', css: 'linear-gradient(160deg, #2193b044, #6dd5ed33 60%, transparent)' },
    forest: { label: 'Hutan', css: 'linear-gradient(160deg, #11998e44, #38ef7d26 60%, transparent)' },
    candy: { label: 'Permen', css: 'linear-gradient(135deg, #f9a8d455, #c4b5fd44 50%, #93c5fd44)' },
    galaxy: { label: 'Galaksi', css: 'radial-gradient(ellipse at top, #7c3aed44, transparent 60%), radial-gradient(ellipse at bottom right, #ec489944, transparent 55%)' },
    dots: { label: 'Titik', css: 'radial-gradient(color-mix(in oklab, var(--m-text) 14%, transparent) 1.5px, transparent 1.6px) 0 0 / 22px 22px' },
    grid: { label: 'Kotak', css: 'linear-gradient(color-mix(in oklab, var(--m-text) 8%, transparent) 1px, transparent 1px) 0 0 / 28px 28px, linear-gradient(90deg, color-mix(in oklab, var(--m-text) 8%, transparent) 1px, transparent 1px) 0 0 / 28px 28px' },
    paper: { label: 'Kertas', css: 'repeating-linear-gradient(0deg, transparent 0 31px, color-mix(in oklab, var(--m-accent) 18%, transparent) 31px 32px)' },
};

export const ICONS = ['📌', '📚', '🧪', '🔬', '🌱', '🌍', '🧮', '📐', '🎨', '🎵', '⚽', '🕌', '⛪', '💡', '✍️', '🗣️', '🧠', '🏆', '🎉', '🚀', '📷', '🎬', '🗺️', '❤️'];
