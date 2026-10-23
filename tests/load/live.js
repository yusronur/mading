// Uji beban kuis live: satu guru menjalankan kuis, banyak siswa menjawab bersamaan.
// Contoh: k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e VUS=200 tests/load/live.js
import http from 'k6/http';
import ws from 'k6/ws';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { BASE, DATA, login, page, studentName, headers, wsUrl, authChannel } from './lib.js';

const VUS = Number(__ENV.VUS || 50);
const SECONDS = Number(__ENV.SECONDS || 20);       // waktu per soal
const QUESTIONS = Number(__ENV.QUESTIONS || 10);
const JOIN_WAIT = Number(__ENV.JOIN_WAIT || 40);    // detik menunggu siswa bergabung
const USE_WS = __ENV.WS !== '0';
const TOTAL = JOIN_WAIT + QUESTIONS * (SECONDS + 8) + 30;

const answerMs = new Trend('live_answer_ms', true);
const reactMs = new Trend('live_question_seen_ms', true); // dari soal dibuka sampai siswa menerimanya
const answered = new Counter('live_answers');

export const options = {
    noCookiesReset: true, // sesi login dipakai ulang antar-iterasi
    scenarios: {
        host: { executor: 'per-vu-iterations', vus: 1, iterations: 1, exec: 'host', maxDuration: `${TOTAL + 60}s` },
        students: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, exec: 'student', startTime: '5s', maxDuration: `${TOTAL + 60}s` },
    },
    thresholds: {
        'http_req_failed': ['rate<0.01'],
        'live_answer_ms': ['p(95)<800'],
        'http_req_duration{name:GET live status}': ['p(95)<500'],
    },
};

// Guru membuat sesi live sebelum siswa mulai
export function setup() {
    login(DATA.teacher);
    const res = http.post(`${BASE}/kuis/${DATA.quiz_id}/live`, JSON.stringify({ seconds: SECONDS, teams: 0, allow_guests: false, leaderboard: true, shuffle_questions: false }), {
        headers: headers({ 'Content-Type': 'application/json', 'X-Inertia': 'true' }), redirects: 0,
    });
    const loc = res.headers.Location || '';
    const m = /\/live\/([0-9a-f-]{36})\/host/.exec(loc);
    if (!m) throw new Error(`gagal membuat sesi live: ${res.status} ${loc}`);
    return { session: m[1], cookies: http.cookieJar().cookiesForURL(BASE) };
}

export function host(data) {
    const jar = http.cookieJar();
    for (const [k, v] of Object.entries(data.cookies)) jar.set(BASE, k, v[0]);
    const act = (action) => http.post(`${BASE}/live/${data.session}/aksi`, JSON.stringify({ action }), {
        headers: headers({ 'Content-Type': 'application/json' }), tags: { name: `POST live ${action}` },
    });
    sleep(JOIN_WAIT);
    check(act('start'), { 'kuis dimulai': (r) => r.status === 200 });
    for (let q = 0; q < QUESTIONS; q++) {
        sleep(SECONDS + 4);
        act('reveal');
        sleep(4);
        act(q === QUESTIONS - 1 ? 'end' : 'next');
    }
}

export function student(data) {
    const i = ((__VU - 1) % DATA.students) + 1;
    sleep(Math.random() * 20); // siswa bergabung tidak serentak
    login(studentName(i));
    const url = `${BASE}/live/${data.session}`;
    const p = page(http.get(url, { tags: { name: 'GET live (gabung)' } }));
    if (!check(p, { 'bergabung ke live': (x) => !!x && x.component === 'Live/Play' })) return;

    let done = {};
    const status = () => {
        const r = http.get(`${url}/status`, { headers: headers(), tags: { name: 'GET live status' } });
        return r.status === 200 ? r.json() : null;
    };
    const handle = (s) => {
        if (!s) return false;
        if (s.phase === 'question' && s.question && !s.me?.answered && !done[s.question.id]) {
            if (s.opens_at) reactMs.add(Math.max(0, Date.now() - Date.parse(s.opens_at)));
            done[s.question.id] = true;
            sleep(1 + Math.random() * 6); // waktu berpikir
            const opts = s.question.options || [];
            const pick = opts.length ? opts[Math.floor(Math.random() * opts.length)].id : null;
            const t0 = Date.now();
            const r = http.post(`${url}/jawab`, JSON.stringify({ question_id: s.question.id, response: pick }), {
                headers: headers({ 'Content-Type': 'application/json' }), tags: { name: 'POST live jawab' },
            });
            answerMs.add(Date.now() - t0);
            if (check(r, { 'jawaban diterima': (x) => x.status === 200 || x.status === 422 })) answered.add(1);
        }
        return s.phase === 'ended';
    };

    const deadline = Date.now() + TOTAL * 1000;
    if (USE_WS && p.props.realtime && p.props.initial?.channel) {
        const name = p.props.initial.channel;
        ws.connect(wsUrl(p.props.realtime), {}, (socket) => {
            socket.on('message', (raw) => {
                const msg = JSON.parse(raw);
                if (msg.event === 'pusher:connection_established') {
                    const auth = authChannel(JSON.parse(msg.data).socket_id, `private-${name}`);
                    if (auth) socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth, channel: `private-${name}` } }));
                } else if (msg.event === 'pusher:ping') {
                    socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                } else if (msg.event === 'live.updated') {
                    // Seperti klien asli: siswa mengabaikan sinyal jumlah pemain/jawaban
                    const kind = JSON.parse(msg.data || '{}').kind;
                    if (kind === 'progress' || kind === 'players') return;
                    sleep(Math.random() * 0.3);
                    if (handle(status())) socket.close();
                }
            });
            // jaring pengaman seperti klien asli
            socket.setInterval(() => { if (handle(status()) || Date.now() > deadline) socket.close(); }, 8000);
        });
    } else {
        while (Date.now() < deadline) {
            if (handle(status())) break;
            sleep(1.5);
        }
    }
}
