import { refreshGamify } from '@/lib/gamify';
// Aktivitas live di papan (timer, poll, word cloud, spin wheel).
// Sumber kebenaran: GET /papan/{id}/aktivitas. Siaran WebSocket hanya memberi tahu
// "ada perubahan"; bila WebSocket tidak tersambung, status diambil berkala.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { getJson, postJson } from '@/lib/http';
import { listen, wsState } from '@/lib/realtime';

const stores = new Map();
function store(boardId) {
    if (!stores.has(boardId)) stores.set(boardId, { activity: ref(null), offset: ref(0), inflight: null });
    return stores.get(boardId);
}

function apply(s, payload) {
    if (!payload) return;
    s.activity.value = payload.activity;
    if (payload.activity?.server_now) s.offset.value = Date.parse(payload.activity.server_now) - Date.now();
}

/** Halaman yang hanya perlu tahu ada/tidaknya aktivitas (mis. proyektor) membaca store bersama. */
export function seedActivity(boardId, initial) {
    const s = store(boardId);
    apply(s, { activity: initial });

    return s.activity;
}

/** Guru memulai aktivitas dari peluncur; panel yang sedang tampil ikut berubah. */
export async function startActivity(boardId, data) {
    const s = store(boardId);
    apply(s, await postJson(route('activities.store', boardId), data));
}

export function useActivity(boardId, initial, channel) {
    const s = store(boardId);
    apply(s, { activity: initial });
    const now = ref(Date.now() + s.offset.value);
    let poll = null;
    let tick = null;
    let stop = () => {};

    // Sinyal yang datang saat pengambilan sedang berjalan tidak boleh hilang: data yang sedang
    // diambil bisa saja dibaca sebelum perubahan terakhir (mis. jeda timer), jadi ulangi sekali lagi.
    const refresh = () => {
        if (s.inflight) { s.again = true; return s.inflight; }
        s.inflight = getJson(route('activities.show', boardId)).then((p) => apply(s, p)).catch(() => {}).finally(() => {
            s.inflight = null;
            if (s.again) { s.again = false; refresh(); }
        });
        return s.inflight;
    };

    const schedule = () => {
        clearTimeout(poll);
        // Sinyal WebSocket bisa terlewat (putus sebentar, server dimulai ulang), jadi saat ada aktivitas
        // yang sedang tampil tetap cek tiap 4 detik; tanpa aktivitas cukup 15 detik bila WebSocket tersambung.
        const ms = document.hidden ? 15000 : s.activity.value ? (wsState.value === 'connected' ? 4000 : 3000) : wsState.value === 'connected' ? 15000 : 3000;
        poll = setTimeout(async () => { await refresh(); schedule(); }, ms);
    };

    const call = async (url, body) => {
        const p = await postJson(url, body);
        apply(s, p);
    };

    onMounted(async () => {
        tick = setInterval(() => { now.value = Date.now() + s.offset.value; }, 250);
        document.addEventListener('visibilitychange', onVisible);
        schedule();
        stop = await listen(usePage().props.realtime, channel, 'board.updated', (p) => { if (p?.kind === 'activity') refresh(); });
    });
    onBeforeUnmount(() => {
        clearInterval(tick);
        clearTimeout(poll);
        document.removeEventListener('visibilitychange', onVisible);
        stop();
    });
    function onVisible() { if (!document.hidden) refresh(); schedule(); }
    watch(wsState, (v) => { if (v === 'connected') refresh(); schedule(); });

    return {
        activity: s.activity,
        now,
        refresh,
        respond: async (value) => { await call(route('activities.respond', [boardId, s.activity.value.id]), { value }); refreshGamify(); },
        act: (action, extra = {}) => call(route('activities.act', [boardId, s.activity.value.id]), { action, ...extra }),
    };
}
