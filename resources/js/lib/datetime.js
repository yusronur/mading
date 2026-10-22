// Format tanggal/waktu Indonesia dan nilai input datetime-local.
export const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '–');

/** Date -> "YYYY-MM-DDTHH:mm" waktu lokal peramban (untuk <input type="datetime-local">). */
export function toLocalInput(date) {
    const d = new Date(date);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function untilText(iso) {
    if (!iso) return 'tanpa tenggat';
    const s = (new Date(iso).getTime() - Date.now()) / 1000;
    if (s <= 0) return 'sudah lewat';
    if (s < 3600) return `${Math.ceil(s / 60)} menit lagi`;
    if (s < 86400) return `${Math.floor(s / 3600)} jam lagi`;
    return `${Math.floor(s / 86400)} hari lagi`;
}
