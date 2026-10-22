import { ref } from 'vue';
import { getJson } from '@/lib/http';
import { listen, wsState } from '@/lib/realtime';

// Status lonceng yang bertahan antar-halaman (Inertia memuat ulang komponen layout tiap navigasi,
// jadi langganan WebSocket tidak boleh ikut dibuat-hapus setiap pindah halaman).
export const unread = ref(0);
export const items = ref([]);

let channel = null;
let stop = () => {};
let timer = null;

export async function load() {
    try {
        const d = await getJson(route('notifications.summary'));
        unread.value = d.unread;
        items.value = d.items;
    } catch (e) { /* diam: lonceng tidak boleh mengganggu halaman */ }
}

const schedule = () => {
    clearTimeout(timer);
    // Cadangan bila WebSocket tidak tersambung: tiap 20 detik; bila tersambung: tiap 2 menit
    timer = setTimeout(() => { if (!document.hidden) load(); schedule(); }, wsState.value === 'connected' ? 120000 : 20000);
};

/** Idempoten: dipanggil tiap Bell dipasang; hanya berlangganan sekali per kanal. */
export function startNotify(realtime, ch) {
    if (!channel) {
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        schedule();
    }
    if (!ch || ch === channel) return;
    stop();
    channel = ch;
    if (realtime) listen(realtime, ch, 'notify.changed', load).then((s) => { stop = s; });
}
