import { onBeforeUnmount, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Pembaruan papan hampir real-time tanpa server WebSocket.
 *
 * Tiap 2 detik (tab terlihat) halaman menanyakan "penanda versi" papan ke
 * server lewat permintaan JSON yang sangat ringan. Kartu baru hanya dimuat
 * bila penandanya berubah. Bila lama tidak ada perubahan, jeda melebar
 * bertahap (maks. 6 detik); tab tersembunyi berhenti total. WebSocket
 * (Reverb) menggantikan ini di Fase 2.
 */
export function useBoardSync(getBoardId, getVersion, only = ['posts', 'board', 'can', 'version', 'theme', 'settings']) {
    let timer = null;
    let known = getVersion();
    let quiet = 0;
    let failures = 0;
    let stopped = false;

    const delay = () => {
        if (failures) return Math.min(30000, 2000 * 2 ** failures);
        if (quiet > 60) return 6000;
        if (quiet > 20) return 4000;
        return 2000;
    };

    const schedule = (ms = delay()) => {
        clearTimeout(timer);
        if (stopped || document.hidden) return;
        timer = setTimeout(tick, ms);
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
                router.reload({ only, preserveScroll: true, preserveState: true });
            } else {
                quiet++;
            }
        } catch (e) {
            failures = Math.min(failures + 1, 4);
        }

        schedule();
    }

    const onVisibility = () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            quiet = 0;
            schedule(100); // cek segera saat tab dibuka lagi
        }
    };

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onVisibility);
        schedule();
    });

    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('online', onVisibility);
    });

    // Setelah kartu dimuat ulang (atau kita sendiri menempel kartu), samakan penanda
    watch(getVersion, (v) => {
        known = v;
    });
}
