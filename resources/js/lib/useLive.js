// Status kuis live untuk layar host dan pemain.
// Sumber kebenaran selalu GET /live/{id}/status. Siaran WebSocket hanya memberi
// tahu "ada perubahan"; bila WebSocket putus, status diambil berkala (QZ-13).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { getJson, postJson } from '@/lib/http';
import { listen, wsState } from '@/lib/realtime';

export function useLive(initial, { host = false } = {}) {
    const state = ref(initial);
    const offset = ref(Date.parse(initial.server_now) - Date.now()); // jam server - jam perangkat
    const now = ref(Date.now() + offset.value);
    const online = ref(true);
    const busy = ref(false);
    let stopListen = () => {};
    let pollTimer = null;
    let clockTimer = null;
    let deadlineTimer = null;
    let inflight = null;

    const apply = (data) => {
        if (!data) return;
        // Abaikan jawaban lama yang tiba belakangan
        if (state.value && data.version < state.value.version && data.phase === state.value.phase) return;
        state.value = data;
        offset.value = Date.parse(data.server_now) - Date.now();
        online.value = true;
    };

    const refresh = () => {
        if (inflight) return inflight;
        inflight = getJson(route('live.state', state.value.id))
            .then(apply)
            .catch(() => { online.value = false; })
            .finally(() => { inflight = null; });
        return inflight;
    };

    // Pengecekan berkala: rapat bila WebSocket tidak tersambung, jarang bila tersambung
    const schedulePoll = () => {
        clearTimeout(pollTimer);
        if (state.value.phase === 'podium') return;
        const fast = wsState.value !== 'connected';
        const ms = document.hidden ? 15000 : fast ? 2000 : 12000;
        pollTimer = setTimeout(async () => { await refresh(); schedulePoll(); }, ms);
    };

    // Saat batas waktu soal lewat, minta status (server menutup soal secara malas)
    const scheduleDeadline = () => {
        clearTimeout(deadlineTimer);
        const d = state.value.deadline_at ? Date.parse(state.value.deadline_at) : null;
        if (!d || state.value.phase !== 'question') return;
        const wait = Math.max(0, d - (Date.now() + offset.value)) + 1800 + (host ? 0 : Math.random() * 700);
        deadlineTimer = setTimeout(refresh, wait);
    };

    const onSignal = (payload) => {
        if (payload?.kind === 'progress') {
            // Hanya hitungan; tidak perlu mengambil status lengkap
            if (host && state.value.phase === 'question') {
                state.value = { ...state.value, answered: payload.answered, players_count: payload.players };
            }
            return;
        }
        if (payload?.kind === 'players' && !host) return;
        refresh();
    };

    onMounted(async () => {
        clockTimer = setInterval(() => { now.value = Date.now() + offset.value; }, 200);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', refresh);
        schedulePoll();
        scheduleDeadline();
        stopListen = await listen(usePage().props.realtime, state.value.channel, 'live.updated', onSignal);
    });
    onBeforeUnmount(() => {
        clearInterval(clockTimer);
        clearTimeout(pollTimer);
        clearTimeout(deadlineTimer);
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('online', refresh);
        stopListen();
    });
    function onVisible() {
        if (!document.hidden) refresh();
        schedulePoll();
    }

    watch(() => [state.value.phase, state.value.index, state.value.deadline_at], scheduleDeadline);
    watch(wsState, (s) => { if (s === 'connected') refresh(); schedulePoll(); });

    const secondsLeft = computed(() => {
        const s = state.value;
        if (s.phase !== 'question' || !s.deadline_at) return null;
        return Math.max(0, Math.ceil((Date.parse(s.deadline_at) - now.value) / 1000));
    });
    const readyLeft = computed(() => {
        const s = state.value;
        if (s.phase !== 'question' || !s.opens_at) return 0;
        return Math.max(0, Math.ceil((Date.parse(s.opens_at) - now.value) / 1000));
    });
    const timeFraction = computed(() => {
        const s = state.value;
        if (s.phase !== 'question' || !s.deadline_at) return 0;
        const total = s.seconds * 1000;
        return Math.min(1, Math.max(0, (Date.parse(s.deadline_at) - now.value) / total));
    });

    const action = async (name, extra = {}) => {
        busy.value = true;
        try {
            apply(await postJson(route('live.action', state.value.id), { action: name, ...extra }));
        } finally {
            busy.value = false;
        }
    };

    return { state, now, online, busy, wsState, secondsLeft, readyLeft, timeFraction, refresh, action, apply };
}

// Bentuk dan warna jawaban (orisinal, bukan simbol Kahoot): bintang, bulan, petir, hati, daun, tetes
export const ANSWER_STYLES = [
    { name: 'Bintang', bg: '#ff6b81', fg: '#1b0f14' },
    { name: 'Bulan', bg: '#2f6fed', fg: '#ffffff' },
    { name: 'Petir', bg: '#ffbe2e', fg: '#1b1405' },
    { name: 'Hati', bg: '#26c6a8', fg: '#06201b' },
    { name: 'Daun', bg: '#7c4ddb', fg: '#ffffff' },
    { name: 'Tetes', bg: '#ff8f3d', fg: '#1f1006' },
];

export const TEAM_COLORS = ['#ff6b81', '#2f6fed', '#ffbe2e', '#26c6a8', '#7c4ddb', '#ff8f3d'];

export const fmtScore = (n) => new Intl.NumberFormat('id-ID').format(n ?? 0);
