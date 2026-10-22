import { reactive } from 'vue';

// Pemberitahuan singkat (toast) berbasis CSS. Dipakai di seluruh aplikasi
// sebagai pengganti dialog alert bawaan peramban.
export const toasts = reactive([]);
let seq = 0;

export function dismissToast(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i >= 0) toasts.splice(i, 1);
}

/**
 * action (opsional): { label, run } menampilkan tombol di dalam toast,
 * mis. "Lihat" untuk membuka kartu. Toast bertombol tampil lebih lama.
 */
export function toast(text, tone = 'success', ms = null, action = null) {
    if (!text) return;
    const id = ++seq;
    // Pesan yang sama tidak ditumpuk
    const dup = toasts.find((t) => t.text === text && t.tone === tone);
    if (dup) dismissToast(dup.id);
    toasts.push({ id, text, tone, action });
    setTimeout(() => dismissToast(id), ms ?? (tone === 'error' ? 7000 : action ? 9000 : 3000));
    return id;
}
