// Koneksi WebSocket (Laravel Reverb lewat laravel-echo + pusher-js).
// Dimuat hanya di halaman yang membutuhkannya. Bila konfigurasi kosong atau
// server WebSocket tidak terjangkau, pemanggil memakai pengecekan berkala.
import { ref } from 'vue';

let echo = null;
let loading = null;
export const wsState = ref('off'); // off | connecting | connected | unavailable

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function getEcho(config) {
    if (!config?.key) return null;
    if (echo) return echo;
    if (loading) return loading;

    loading = (async () => {
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            window.Pusher = Pusher;
            const tls = config.scheme === 'https';
            echo = new Echo({
                broadcaster: 'reverb',
                key: config.key,
                wsHost: config.host,
                wsPort: config.port,
                wssPort: config.port,
                wsPath: config.path || '',
                forceTLS: tls,
                enabledTransports: tls ? ['wss'] : ['ws'],
                disableStats: true,
                // Otorisasi kanal privat memakai sesi + XSRF yang sama dengan aplikasi
                authorizer: (channel) => ({
                    authorize: (socketId, callback) => {
                        fetch('/broadcasting/auth', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                        })
                            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`auth ${r.status}`))))
                            .then((data) => callback(null, data))
                            .catch((e) => callback(e, null));
                    },
                }),
            });
            const conn = echo.connector.pusher.connection;
            wsState.value = 'connecting';
            conn.bind('state_change', ({ current }) => {
                wsState.value = current === 'connected' ? 'connected' : current === 'connecting' || current === 'initialized' ? 'connecting' : 'unavailable';
            });
            return echo;
        } catch (e) {
            wsState.value = 'unavailable';
            return null;
        }
    })();

    return loading;
}

/** Berlangganan kanal privat; mengembalikan fungsi berhenti. */
export async function listen(config, channel, event, handler) {
    const e = await getEcho(config);
    if (!e) return () => {};
    const ch = e.private(channel);
    ch.listen(`.${event}`, handler);

    return () => {
        try { e.leave(channel); } catch (err) { /* abaikan */ }
    };
}
