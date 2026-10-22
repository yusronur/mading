<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { applyTheme } from '@/lib/theme';
import Flash from '@/Components/Flash.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Btn from '@/Components/Btn.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import AnswerShape from '@/Components/Live/AnswerShape.vue';
import ReadyCount from '@/Components/Live/ReadyCount.vue';
import { useLive, ANSWER_STYLES, TEAM_COLORS, fmtScore } from '@/lib/useLive';
import { askConfirm } from '@/lib/confirm';
import { toast } from '@/lib/toast';
import { confetti } from '@/lib/confetti';

// Layar proyektor guru (QZ-10, QZ-11, BRD-20): teks besar, tanpa navigasi aplikasi.
const props = defineProps({ initial: Object });
const page = usePage();
watch(() => page.props.theme, (t) => applyTheme(t), { immediate: true, deep: true });

const { state, busy, wsState, secondsLeft, readyLeft, timeFraction, action } = useLive(props.initial, { host: true });
const s = computed(() => state.value);
const q = computed(() => s.value.question);
const players = computed(() => s.value.host?.players ?? []);

const qr = ref('');
onMounted(async () => {
    qr.value = await QRCode.toDataURL(s.value.host.join_url, { margin: 1, width: 420, errorCorrectionLevel: 'M' });
});
const joinHost = computed(() => {
    try {
        const u = new URL(s.value.host.join_url);
        return u.host + (s.value.host.allow_guests ? '/tamu' : '');
    } catch (e) { return ''; }
});

const run = async (name, extra) => {
    try { await action(name, extra); } catch (e) { toast(e.message, 'error'); }
};
const end = async () => {
    if (await askConfirm({ title: 'Akhiri kuis sekarang?', message: 'Soal yang tersisa dilewati dan podium langsung ditampilkan. Nilai tersimpan ke laporan.', confirmText: 'Akhiri', tone: 'danger' })) run('end');
};
const kick = async (p) => {
    if (await askConfirm({ title: `Keluarkan ${p.name}?`, message: 'Pemain ini tidak bisa bergabung lagi ke kuis ini.', confirmText: 'Keluarkan', tone: 'danger' })) run('kick', { user_id: p.user_id });
};
const fullscreen = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.());

const lastIndex = computed(() => s.value.index >= s.value.total - 1);
const nextLabel = computed(() => {
    if (s.value.phase === 'reveal' && s.value.host.leaderboard_on) return 'Papan peringkat →';
    return lastIndex.value ? 'Lihat podium 🏆' : 'Soal berikutnya →';
});
// Jumlah pemilih tiap pilihan: live saat soal berjalan, final saat pembahasan
const counts = computed(() => (s.value.phase === 'reveal'
    ? (s.value.reveal?.distribution ?? []).map((d) => d.count)
    : (s.value.host_counts ?? [])));
const countOf = (i) => counts.value[i] ?? 0;
const maxCount = computed(() => Math.max(1, ...counts.value));
const choice = computed(() => ['mc', 'mcm', 'tf'].includes(q.value?.type));
const keyText = computed(() => {
    const r = s.value.reveal;
    if (!r || !q.value) return '';
    if (q.value.type === 'short') return r.key.join(' / ');
    if (q.value.type === 'match') return '';
    return '';
});
const podium = computed(() => (s.value.leaderboard?.players ?? []).slice(0, 3));
const teamColor = (t) => TEAM_COLORS[(t - 1) % TEAM_COLORS.length];

watch(() => s.value.phase, (p) => { if (p === 'podium') setTimeout(() => confetti({ duration: 4000, count: 220 }), 600); }, { immediate: true });
</script>

<template>
    <Head :title="`Live: ${s.title}`" />
    <div class="flex min-h-dvh flex-col bg-bg text-ink">
        <Flash />
        <ConfirmDialog />

        <!-- Bilah atas -->
        <header class="flex flex-wrap items-center gap-3 border-b border-line/60 px-4 py-3 sm:px-6">
            <Link :href="route('quizzes.index')" class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold text-muted hover:text-ink">← Kuis</Link>
            <h1 class="min-w-0 flex-1 truncate font-display text-xl font-bold">{{ s.title }}</h1>
            <span class="rounded-xl bg-surface2 px-3 py-1 font-mono text-lg font-bold tracking-[0.2em]" title="Kode kuis">{{ s.code }}</span>
            <span class="flex items-center gap-1.5 text-xs font-bold text-muted" :title="wsState === 'connected' ? 'Tersambung langsung (WebSocket)' : 'Pengecekan berkala'">
                <span class="size-2.5 rounded-full" :class="wsState === 'connected' ? 'bg-success' : 'bg-warning'"></span>{{ wsState === 'connected' ? 'Langsung' : 'Berkala' }}
            </span>
            <button class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold" @click="fullscreen">⛶ Layar penuh</button>
            <button v-if="s.phase !== 'podium'" class="rounded-lg px-3 py-1.5 text-sm font-bold text-danger hover:bg-surface2" @click="end">Akhiri</button>
        </header>

        <!-- LOBI -->
        <main v-if="s.phase === 'lobby'" class="grid flex-1 gap-6 p-4 sm:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
            <section class="flex flex-col items-center justify-center rounded-card border border-line bg-surface p-6 text-center shadow-card">
                <p class="text-lg font-bold text-muted">Buka</p>
                <p class="font-display text-3xl font-bold break-all sm:text-4xl">{{ joinHost }}</p>
                <p class="mt-3 text-lg font-bold text-muted">lalu masukkan kode</p>
                <p class="font-mono text-6xl font-bold tracking-[0.25em] text-accent sm:text-8xl" data-testid="live-code">{{ s.code }}</p>
                <img v-if="qr" :src="qr" alt="QR untuk bergabung" class="mt-5 w-56 rounded-2xl bg-white p-2 sm:w-64" />
                <p v-if="s.host.allow_guests" class="mt-3 text-sm font-semibold text-muted">Tamu tanpa akun boleh ikut dengan nama.</p>
            </section>
            <section class="flex flex-col rounded-card border border-line bg-surface p-6 shadow-card">
                <div class="flex items-end justify-between gap-3">
                    <h2 class="text-3xl font-bold">Pemain <span class="text-accent">{{ players.length }}</span></h2>
                    <Btn size="lg" :loading="busy" :disabled="!players.length" @click="run('start')">Mulai ▶</Btn>
                </div>
                <p v-if="s.teams.length" class="mt-2 text-sm text-muted">Mode tim: pemain dibagi rata ke {{ s.teams.length }} tim otomatis.</p>
                <TransitionGroup tag="ul" name="card" class="mt-5 flex flex-wrap content-start gap-2">
                    <li v-for="p in players" :key="p.user_id" class="group flex items-center gap-2 rounded-full bg-surface2 py-1.5 pr-1.5 pl-4 text-lg font-bold">
                        <span v-if="p.team" class="size-3 rounded-full" :style="{ background: teamColor(p.team) }"></span>
                        {{ p.name }}<span v-if="p.guest" class="text-xs font-semibold text-muted">tamu</span>
                        <button class="grid size-7 place-items-center rounded-full text-sm text-muted hover:bg-danger hover:text-bg" :aria-label="`Keluarkan ${p.name}`" @click="kick(p)">✕</button>
                    </li>
                </TransitionGroup>
                <p v-if="!players.length" class="m-auto py-10 text-center text-xl font-semibold text-muted">Menunggu pemain bergabung…</p>
            </section>
        </main>

        <!-- SOAL dan PEMBAHASAN -->
        <main v-else-if="q && (s.phase === 'question' || s.phase === 'reveal')" class="flex flex-1 flex-col gap-5 p-4 sm:p-8">
            <div class="flex items-center gap-4">
                <span class="rounded-xl bg-surface2 px-3 py-1 font-bold text-muted">Soal {{ s.index + 1 }}/{{ s.total }}</span>
                <div v-if="s.phase === 'question'" class="h-3 flex-1 overflow-hidden rounded-full bg-surface2">
                    <div class="h-full rounded-full transition-[width] duration-200 ease-linear" :class="secondsLeft <= 5 ? 'bg-danger' : 'bg-accent'" :style="{ width: (readyLeft ? 100 : timeFraction * 100) + '%' }"></div>
                </div>
                <div v-else class="flex-1"></div>
                <span v-if="s.phase === 'question'" class="grid size-20 place-items-center rounded-full border-4 font-display text-4xl font-bold" :class="secondsLeft <= 5 && !readyLeft ? 'border-danger text-danger' : 'border-accent'" data-testid="countdown">{{ readyLeft ? '…' : secondsLeft }}</span>
                <span class="rounded-xl bg-surface2 px-4 py-2 text-xl font-bold" data-testid="answered">{{ s.answered }}/{{ s.players_count }} <span class="text-sm text-muted">menjawab</span></span>
            </div>

            <ReadyCount v-if="s.phase === 'question' && readyLeft" :n="readyLeft" big class="flex-1" />

            <div v-if="!(s.phase === 'question' && readyLeft)" class="rounded-card border border-line bg-surface p-6 text-center shadow-card">
                <MathHtml :html="q.prompt" class="rich font-display text-2xl leading-snug font-bold sm:text-4xl" />
                <div v-if="q.media.length" class="mt-4 flex flex-wrap justify-center gap-3">
                    <template v-for="m in q.media" :key="m.id">
                        <img v-if="m.kind === 'image'" :src="m.url" :alt="m.name" class="max-h-[34vh] w-auto rounded-xl object-contain" />
                        <audio v-else-if="m.kind === 'audio'" :src="m.url" controls class="w-full max-w-md"></audio>
                        <video v-else-if="m.kind === 'video'" :src="m.url" controls class="max-h-[34vh] rounded-xl"></video>
                    </template>
                </div>
            </div>

            <!-- Pilihan (ganda/benar-salah) -->
            <div v-if="choice && !(s.phase === 'question' && readyLeft)" class="grid flex-1 gap-3 sm:grid-cols-2">
                <div v-for="(o, i) in q.options" :key="o.id" class="relative flex min-h-20 items-center gap-4 overflow-hidden rounded-card px-5 py-4 text-2xl font-bold shadow-card transition-opacity sm:text-3xl"
                    :style="{ background: ANSWER_STYLES[i % 6].bg, color: ANSWER_STYLES[i % 6].fg }"
                    :class="s.phase === 'reveal' && !s.reveal.distribution[i]?.correct ? 'opacity-35' : ''">
                    <AnswerShape :index="i" :size="40" />
                    <MathHtml :html="o.text" plain tag="span" class="min-w-0 flex-1" />
                    <span class="min-w-14 rounded-xl bg-black/20 px-3 py-1 text-center font-mono text-3xl font-black tabular-nums" data-testid="option-count">{{ countOf(i) }}</span>
                    <span v-if="s.phase === 'reveal' && s.reveal.distribution[i]?.correct" class="text-4xl" aria-label="Jawaban benar">✓</span>
                    <span class="absolute inset-x-0 bottom-0 h-2 origin-left bg-black/25 transition-[width] duration-500" :style="{ width: (countOf(i) / maxCount) * 100 + '%' }"></span>
                </div>
            </div>
            <div v-else-if="s.phase === 'question' && !readyLeft" class="flex flex-1 items-center justify-center rounded-card border-2 border-dashed border-line p-6 text-center text-2xl font-bold text-muted">
                {{ q.type === 'short' ? '✍️ Siswa mengetik jawaban di HP' : '🔗 Siswa menjodohkan pasangan di HP' }}
            </div>

            <!-- Pembahasan isian / menjodohkan -->
            <div v-if="s.phase === 'reveal' && !choice" class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-card border border-line bg-surface p-5">
                    <p class="text-sm font-bold text-muted uppercase">{{ q.type === 'short' ? 'Jawaban benar' : 'Pasangan benar' }}</p>
                    <p v-if="q.type === 'short'" class="mt-1 text-3xl font-bold text-success">{{ keyText }}</p>
                    <ul v-else class="mt-2 space-y-1 text-xl font-bold">
                        <li v-for="d in s.reveal.distribution" :key="d.id">{{ d.text }} <span class="text-sm text-muted">({{ d.count }} benar)</span></li>
                    </ul>
                </div>
                <div v-if="q.type === 'short'" class="rounded-card border border-line bg-surface p-5">
                    <p class="text-sm font-bold text-muted uppercase">Jawaban siswa</p>
                    <ul class="mt-2 space-y-1 text-xl">
                        <li v-for="d in s.reveal.distribution" :key="d.id" :class="d.correct ? 'font-bold text-success' : 'text-ink'">{{ d.text }} · {{ d.count }}</li>
                        <li v-if="!s.reveal.distribution.length" class="text-muted">Belum ada jawaban.</li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p v-if="s.phase === 'reveal'" class="text-xl font-bold">
                    <span class="text-success">{{ s.reveal.correct_count }}</span> dari {{ s.reveal.answered }} menjawab benar
                </p>
                <MathHtml v-if="s.phase === 'reveal' && s.reveal.explanation" :html="s.reveal.explanation" class="rich w-full rounded-xl bg-surface2 p-4 text-lg" />
                <span class="flex-1"></span>
                <Btn v-if="s.phase === 'question'" variant="soft" size="lg" :loading="busy" @click="run('reveal')">Tutup soal</Btn>
                <Btn v-else size="lg" :loading="busy" @click="run('next')">{{ nextLabel }}</Btn>
            </div>
        </main>

        <!-- PAPAN PERINGKAT -->
        <main v-else-if="s.phase === 'leaderboard'" class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-5 p-4 sm:p-8">
            <h2 class="text-center font-display text-4xl font-bold">Papan peringkat</h2>
            <div v-if="s.leaderboard.teams.length" class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${s.leaderboard.teams.length}, minmax(0, 1fr))` }">
                <div v-for="t in s.leaderboard.teams" :key="t.team" class="rounded-card p-4 text-center font-bold" :style="{ background: teamColor(t.team), color: '#111' }">
                    <p class="text-lg">Tim {{ t.name }}</p><p class="text-3xl">{{ fmtScore(t.score) }}</p><p class="text-xs">{{ t.members }} anggota · rata-rata</p>
                </div>
            </div>
            <TransitionGroup tag="ol" name="card" class="space-y-2">
                <li v-for="p in s.leaderboard.players" :key="p.user_id" class="flex items-center gap-4 rounded-card border border-line bg-surface px-5 py-3 text-2xl font-bold shadow-card">
                    <span class="w-10 text-center text-muted">{{ p.rank }}</span>
                    <span v-if="p.team" class="size-4 rounded-full" :style="{ background: teamColor(p.team) }"></span>
                    <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
                    <span class="font-mono">{{ fmtScore(p.score) }}</span>
                </li>
            </TransitionGroup>
            <div class="flex justify-end"><Btn size="lg" :loading="busy" @click="run('next')">{{ lastIndex ? 'Lihat podium 🏆' : 'Soal berikutnya →' }}</Btn></div>
        </main>

        <!-- PODIUM -->
        <main v-else-if="s.phase === 'podium'" class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center gap-6 p-4 sm:p-8">
            <h2 class="font-display text-5xl font-bold">🏆 Juara!</h2>
            <div class="grid w-full grid-cols-3 items-end gap-3">
                <div v-for="slot in [1, 0, 2]" :key="slot" class="flex flex-col items-center gap-2">
                    <template v-if="podium[slot]">
                        <p class="max-w-full truncate text-center text-2xl font-bold sm:text-3xl">{{ podium[slot].name }}</p>
                        <p class="font-mono text-xl text-muted">{{ fmtScore(podium[slot].score) }}</p>
                        <div class="podium-rise flex w-full items-start justify-center rounded-t-card pt-3 font-display text-5xl font-bold text-bg"
                            :class="slot === 0 ? 'h-56 bg-warning' : slot === 1 ? 'h-40 bg-accent3' : 'h-28 bg-accent2'"
                            :style="{ animationDelay: slot === 0 ? '1.2s' : slot === 1 ? '.6s' : '0s' }">{{ slot + 1 }}</div>
                    </template>
                </div>
            </div>
            <div v-if="s.leaderboard.teams.length" class="rounded-card bg-surface px-6 py-4 text-center text-2xl font-bold shadow-card">
                Tim juara: <span :style="{ color: teamColor(s.leaderboard.teams[0].team) }">{{ s.leaderboard.teams[0].name }}</span> ({{ fmtScore(s.leaderboard.teams[0].score) }})
            </div>
            <div class="flex flex-wrap gap-3">
                <Btn :href="s.host.report_url" size="lg">📊 Lihat laporan</Btn>
                <Btn :href="route('quizzes.index')" variant="soft" size="lg">Selesai</Btn>
            </div>
        </main>
    </div>
</template>

<style scoped>
.podium-rise { animation: rise 0.7s cubic-bezier(.2,.8,.2,1.2) both; transform-origin: bottom; }
@keyframes rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
@media (prefers-reduced-motion: reduce) { .podium-rise { animation: none; } }
</style>
