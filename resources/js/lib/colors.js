// Warna kartu. Latar dicampur dengan warna permukaan tema agar tetap terbaca
// di tema gelap maupun terang.
export const CARD_COLORS = {
    red: '#ef4444', orange: '#f97316', yellow: '#eab308', green: '#22c55e',
    teal: '#14b8a6', blue: '#3b82f6', purple: '#a855f7', pink: '#ec4899',
};

export function cardStyle(color) {
    const c = CARD_COLORS[color];
    if (!c) return {};
    return {
        borderTopColor: c,
        background: `color-mix(in oklab, ${c} 16%, var(--m-surface))`,
    };
}
