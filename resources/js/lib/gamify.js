import { router } from '@inertiajs/vue3';

/** Ambil ulang hanya data gamifikasi (XP/pemberitahuan terbaru) tanpa memuat ulang halaman. */
export function refreshGamify() {
    router.reload({ only: ['gamify'], preserveScroll: true, preserveState: true });
}
