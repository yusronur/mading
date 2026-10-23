// Fungsi bersama skrip uji beban Madingo (k6). Lihat README.md di folder ini.
import http from 'k6/http';
import { check, fail } from 'k6';

export const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
export const DATA = JSON.parse(__ENV.DATA ? open(__ENV.DATA) : open('./loadtest.json'));

export function xsrf() {
    const c = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return c && c.length ? decodeURIComponent(c[0]) : '';
}

export function headers(extra = {}) {
    return Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, extra);
}

/** Ambil objek halaman Inertia dari HTML (props + version). */
export function page(res) {
    const m = /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/.exec(res.body || '');
    return m ? JSON.parse(m[1]) : null;
}

export function studentName(i) {
    return `${DATA.student_prefix}${String(i).padStart(4, '0')}`;
}

/** Masuk dengan akun; cookie sesi disimpan di cookie jar milik VU. */
export function login(username, password = DATA.password) {
    http.get(`${BASE}/masuk`, { tags: { name: 'GET /masuk' } });
    const res = http.post(`${BASE}/masuk`, JSON.stringify({ username, password }), {
        headers: headers({ 'Content-Type': 'application/json', 'X-Inertia': 'true' }),
        redirects: 0,
        tags: { name: 'POST /masuk' },
    });
    const ok = check(res, { 'login berhasil (302/409)': (r) => r.status === 302 || r.status === 409 });
    if (!ok) fail(`login ${username} gagal: ${res.status}`);
}

/** Inertia partial reload (seperti router.reload({ only })). */
export function partial(url, component, version, only, name) {
    return http.get(url, {
        headers: headers({ 'X-Inertia': 'true', 'X-Inertia-Version': version, 'X-Inertia-Partial-Component': component, 'X-Inertia-Partial-Data': only.join(',') }),
        tags: { name },
    });
}

/**
 * Klien Pusher/Reverb minimal di atas k6/ws: sambung, otorisasi kanal privat
 * lewat /broadcasting/auth (sesi VU), lalu panggil onEvent(event, data).
 */
export function wsUrl(cfg) {
    const scheme = cfg.scheme === 'https' ? 'wss' : 'ws';
    return `${scheme}://${cfg.host}:${cfg.port}${cfg.path || ''}/app/${cfg.key}?protocol=7&client=k6&version=8.4.0&flash=false`;
}

export function authChannel(socketId, channel) {
    const res = http.post(`${BASE}/broadcasting/auth`, JSON.stringify({ socket_id: socketId, channel_name: channel }), {
        headers: headers({ 'Content-Type': 'application/json' }),
        tags: { name: 'POST /broadcasting/auth' },
    });
    return res.status === 200 ? res.json('auth') : null;
}
