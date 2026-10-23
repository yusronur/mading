// Uji beban papan (Tahap 20): banyak siswa membuka satu papan, sebagian menempel kartu.
//   Mode realtime (bawaan): tiap siswa tersambung ke Reverb dan memuat ulang kartu saat ada sinyal.
//   Mode cadangan: WS=0 → tiap siswa mengecek /sinkron tiap 2 detik (perilaku tanpa Reverb).
// Contoh: k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e VUS=200 tests/load/board.js
import http from 'k6/http';
import ws from 'k6/ws';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { BASE, DATA, login, page, partial, studentName, headers, wsUrl, authChannel } from './lib.js';

const VUS = Number(__ENV.VUS || 50);
const DURATION = __ENV.DURATION || '2m';
const USE_WS = __ENV.WS !== '0';
const POST_EVERY = Number(__ENV.POST_EVERY || 45); // rata-rata detik antar-kartu per siswa

const signalDelay = new Trend('board_signal_to_cards_ms', true);
const ownPostSeen = new Trend('board_own_post_signal_ms', true);
const reloads = new Counter('board_reloads');
const signals = new Counter('board_signals');

export const options = {
    noCookiesReset: true, // sesi login dipakai ulang antar-iterasi
    scenarios: {
        students: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '30s', target: VUS }, { duration: DURATION, target: VUS }, { duration: '10s', target: 0 }], gracefulRampDown: '10s' },
    },
    thresholds: {
        'http_req_failed': ['rate<0.01'],
        'http_req_duration{name:GET papan (partial)}': ['p(95)<800'],
        'http_req_duration{name:POST kartu}': ['p(95)<1000'],
        'board_signal_to_cards_ms': ['p(95)<1500'],
    },
};

const boardUrl = `${BASE}/papan/${DATA.board_id}`;

function postCard(i) {
    const t0 = Date.now();
    const res = http.post(`${boardUrl}/kartu`, JSON.stringify({ title: `Uji beban ${i} ${t0}`, body: '<p>Kartu dari k6</p>' }), {
        headers: headers({ 'Content-Type': 'application/json', 'X-Inertia': 'true' }), redirects: 0, tags: { name: 'POST kartu' },
    });
    check(res, { 'kartu terkirim': (r) => r.status === 302 || r.status === 303 || r.status === 409 });
    return t0;
}

export default function () {
    const i = ((__VU - 1) % DATA.students) + 1;
    if (__ITER === 0) login(studentName(i));

    const first = http.get(boardUrl, { tags: { name: 'GET papan (halaman)' } });
    const p = page(first);
    if (!check(p, { 'halaman papan terbaca': (x) => !!x && x.component === 'Boards/Show' })) {
        if (__ENV.DEBUG) console.log(`VU${__VU} halaman gagal: ${first.status} ${first.url} ${(first.body || '').slice(0, 120)}`);
        sleep(5);
        return;
    }
    let version = p.version;
    const reload = (name = 'GET papan (partial)') => {
        reloads.add(1);
        const r = partial(boardUrl, 'Boards/Show', version, ['posts', 'sections', 'board', 'can', 'version'], name);
        if (r.status === 200) { try { p.props.version = r.json('props.version'); } catch (e) { /* abaikan */ } }
        return r;
    };

    const until = Date.now() + 60000; // satu iterasi = satu kunjungan ±60 detik
    let nextPost = Date.now() + Math.random() * POST_EVERY * 2000;
    let pendingOwn = null;

    if (USE_WS && p.props.realtime) {
        const channel = `private-${p.props.activity_channel}`;
        ws.connect(wsUrl(p.props.realtime), {}, (socket) => {
            socket.on('message', (raw) => {
                const msg = JSON.parse(raw);
                if (__ENV.DEBUG) console.log(`VU${__VU} ${raw.slice(0, 200)}`);
                if (msg.event === 'pusher:connection_established') {
                    const auth = authChannel(JSON.parse(msg.data).socket_id, channel);
                    if (auth) socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth, channel } }));
                    else socket.close();
                } else if (msg.event === 'pusher:ping') {
                    socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                } else if (msg.event === 'board.updated') {
                    const data = JSON.parse(msg.data);
                    if (data.kind !== 'posts' || data.v === p.props.version) return;
                    signals.add(1);
                    const t = Date.now();
                    if (pendingOwn) { ownPostSeen.add(t - pendingOwn); pendingOwn = null; }
                    sleep(Math.random() * 0.6); // jeda acak seperti klien asli
                    reload();
                    signalDelay.add(Date.now() - t);
                }
            });
            socket.setInterval(() => {
                if (Date.now() > until) socket.close();
                if (Date.now() > nextPost) {
                    pendingOwn = postCard(i);
                    nextPost = Date.now() + POST_EVERY * 1000 * (0.5 + Math.random());
                }
            }, 1000);
            socket.setTimeout(() => socket.close(), 65000);
        });
    } else {
        // Cadangan tanpa WebSocket: tanya penanda versi tiap 2 detik
        let known = p.props.version;
        while (Date.now() < until) {
            const r = http.get(`${boardUrl}/sinkron`, { headers: headers(), tags: { name: 'GET sinkron' } });
            if (r.status === 200 && r.json('v') !== known) { known = r.json('v'); reload(); }
            if (Date.now() > nextPost) { postCard(i); nextPost = Date.now() + POST_EVERY * 1000 * (0.5 + Math.random()); }
            sleep(2);
        }
    }
}
