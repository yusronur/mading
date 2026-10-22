import { computed, ref } from 'vue';
import { postJson } from '@/lib/http';

// PWA dan Web Push di sisi peramban. Semua fungsi aman dipanggil di peramban tanpa dukungan.

export const installEvent = ref(null);
export const installed = ref(false);

export const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
export const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

export function setupPwa() {
    if (isStandalone()) installed.value = true;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installEvent.value = e;
    });
    window.addEventListener('appinstalled', () => {
        installed.value = true;
        installEvent.value = null;
    });
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
}

export async function promptInstall() {
    const e = installEvent.value;
    if (!e) return false;
    e.prompt();
    const choice = await e.userChoice.catch(() => null);
    installEvent.value = null;
    return choice?.outcome === 'accepted';
}

const toKey = (b64) => {
    const raw = atob((b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

async function registration() {
    if (!pushSupported()) return null;
    return Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(() => r(null), 4000))]);
}

export async function currentSubscription() {
    const reg = await registration();
    return reg ? reg.pushManager.getSubscription() : null;
}

/** Minta izin, berlangganan, dan daftarkan perangkat ini ke server. */
export async function enablePush(vapidKey) {
    if (!pushSupported()) throw new Error('Peramban ini belum mendukung notifikasi push.');
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('Izin notifikasi ditolak. Ubah di pengaturan situs pada peramban untuk mengaktifkannya.');
    const reg = await registration();
    if (!reg) throw new Error('Service worker belum siap. Muat ulang halaman lalu coba lagi.');
    const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(vapidKey) }));
    return postJson(route('push.subscribe'), sub.toJSON());
}

export async function disablePush() {
    const sub = await currentSubscription();
    if (!sub) return null;
    const endpoint = sub.endpoint;
    await sub.unsubscribe().catch(() => {});
    return postJson(route('push.unsubscribe'), { endpoint }, 'DELETE').catch(() => null);
}

/**
 * Perangkat bersama: saat halaman dibuka, langganan yang sudah ada dikaitkan ke akun yang sedang masuk
 * (server memindahkan kepemilikannya), sehingga notifikasi tidak nyasar ke pengguna sebelumnya.
 */
export async function syncDevice(vapidKey) {
    if (!vapidKey || !pushSupported() || Notification.permission !== 'granted') return;
    const sub = await currentSubscription();
    if (sub) postJson(route('push.subscribe'), sub.toJSON()).catch(() => {});
}

// Pemasangan aplikasi: tersedia bila peramban menawarkan (Android/laptop) atau di iPhone/iPad (petunjuk manual)
export const installable = computed(() => !installed.value && !isStandalone() && (!!installEvent.value || isIOS()));
export const iosHelp = ref(false);

export async function installApp() {
    if (installEvent.value) return promptInstall();
    if (isIOS()) iosHelp.value = true;
    return false;
}
