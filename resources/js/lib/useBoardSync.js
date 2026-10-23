import { onBeforeUnmount, onMounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { listen, wsState } from '@/lib/realtime';

/**
 * Pembaruan papan realtime (Tahap 20).
 *
 * Utama: sinyal WebSocket (Reverb) "board.updated" kind=posts beserta penanda
 * versi. Kartu dimuat ulang hanya bila versinya berbeda, dengan jeda acak kecil
 * supaya puluhan siswa tidak memuat ulang di milidetik yang sama.
 *
 * Cadangan: bila WebSocket tidak tersambung, halaman menanyakan penanda versi
 * tiap 2 detik (melebar sampai 6 detik bila sepi). Saat WebSocket tersambung,
 * pengecekan tetap berjalan sangat jarang (30 detik) sebagai jaring pengaman
 * untuk sinyal yang terlewat dan perubahan jadwal buka-tutup.
 * Tab tersembunyi tidak memuat apa pun; saat dibuka lagi langsung dicek.
 */
export function useBoardSync(getBoardId, getVersion, only = ['posts', 'board', 'can', 'version', 'theme', 'settings'], getChannel = null) {
    let timer = null;
    let known = getVersion();
    let quiet = 0;
    let failures = 0;
    let stopped = false;
    let stopWs = () => {};
    let reloading = false;
    let again = false;
    let pendingSignal = null;
    let dirtyWhileHidden = false;

    const live = () => wsState.value === 'connected';

    const delay = () => {
        if (failures) return Math.min(30000, 2000 * 2 ** failures);
        if (live()) return 30000;
        if (quiet > 60) return 6000;
        if (quiet > 20) return 4000;
        return 2000;
    };

    let nextAt = 0;
    const schedule = (ms = delay()) => {
        clearTimeout(timer);
        nextAt = 0;
        if (stopped || document.hidden) return;
        nextAt = Date.now() + ms;
        timer = setTimeout(tick, ms);
    };
    // Majukan pengecekan bila perlu, tanpa pernah menundanya (status WebSocket bisa berganti-ganti cepat)
    const soon = (ms) => {
        if (!nextAt || Date.now() + ms < nextAt) schedule(ms);
    };

    // Satu pemuatan ulang pada satu waktu; sinyal yang datang di tengahnya diulang sekali
    const reload = () => {
        if (reloading) { again = true; return; }
        reloading = true;
        router.reload({
            only,
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                reloading = false;
                if (again) { again = false; reload(); }
            },
        });
    };

    async function tick() {
        try {
            const res = await fetch(route('boards.sync', getBoardId()), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if ([401, 403, 404, 419].includes(res.status)) {
                // Sesi habis atau papan sudah tidak bisa diakses: muat ulang agar
                // pengguna diarahkan ke halaman yang sesuai
                stopped = true;
                router.visit(route('home'), { replace: true });
                return;
            }
            if (!res.ok) throw new Error(String(res.status));

            const { v } = await res.json();
            failures = 0;

            if (v !== known) {
                known = v;
                quiet = 0;
                reload();
            } else {
                quiet++;
            }
        } catch (e) {
            failures = Math.min(failures + 1, 4);
        }

        schedule();
    }

    const onSignal = (p) => {
        if (p?.kind !== 'posts') return;
        if (p.v && p.v === known) return; // perubahan kita sendiri yang sudah termuat
        if (document.hidden) { dirtyWhileHidden = true; return; }
        quiet = 0;
        // Jeda acak 0–600 ms meratakan beban server saat banyak siswa membuka papan yang sama
        clearTimeout(pendingSignal);
        pendingSignal = setTimeout(() => {
            if (p.v) known = p.v;
            reload();
        }, Math.random() * 600);
    };

    const onVisibility = () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            quiet = 0;
            if (dirtyWhileHidden) { dirtyWhileHidden = false; reload(); }
            schedule(100); // cek segera saat tab dibuka lagi
        }
    };

    // Saat WebSocket tersambung/terputus, atur ulang jeda pengecekan cadangan
    const stopWatchWs = watch(wsState, (s, prev) => {
        if (s === 'connected') soon(300);              // baru tersambung: kejar sinyal yang mungkin terlewat
        else if (prev === 'connected') soon(1000);     // terputus: kembali ke pengecekan cepat
    });

    onMounted(async () => {
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onVisibility);
        schedule();
        const channel = getChannel?.();
        if (channel) stopWs = await listen(usePage().props.realtime, channel, 'board.updated', onSignal);
        if (stopped) stopWs();
    });

    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        clearTimeout(pendingSignal);
        stopWatchWs();
        stopWs();
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('online', onVisibility);
    });

    // Setelah kartu dimuat ulang (atau kita sendiri menempel kartu), samakan penanda
    watch(getVersion, (v) => {
        known = v;
    });
}
