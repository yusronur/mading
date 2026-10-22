<script setup>
import { refreshGamify } from '@/lib/gamify';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { applyTheme } from '@/lib/theme';
import Flash from '@/Components/Flash.vue';
import Btn from '@/Components/Btn.vue';
import Mascot from '@/Components/Mascot.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import MatchAnswer from '@/Components/Quiz/MatchAnswer.vue';
import AnswerShape from '@/Components/Live/AnswerShape.vue';
import ReadyCount from '@/Components/Live/ReadyCount.vue';
import { useLive, ANSWER_STYLES, TEAM_COLORS, fmtScore } from '@/lib/useLive';
import { postJson } from '@/lib/http';
import { confetti } from '@/lib/confetti';

// Layar pemain di HP (QZ-10, QZ-13): tombol besar, satu kali jawab per soal.
const props = defineProps({ initial: Object });
const page = usePage();
watch(() => page.props.theme, (t) => applyTheme(t), { immediate: true, deep: true });

const { state, online, secondsLeft, readyLeft, refresh } = useLive(props.initial);
const s = computed(() => state.value);
const me = computed(() => s.value.me ?? {});
const q = computed(() => s.value.question);
// Ukuran teks pilihan: besar agar terbaca di HP; pilihan panjang sedikit diperkecil
const optClass = (html) => {
    const n = String(html ?? '').replace(/<[^>]*>/g, '').length;
    return n <= 14 ? 'text-3xl' : n <= 40 ? 'text-2xl' : n <= 90 ? 'text-xl' : 'text-lg';
};
const choice = computed(() => ['mc', 'mcm', 'tf'].includes(q.value?.type));

// Jawaban lokal per soal
const picked = ref([]);
const text = ref('');
const pairs = ref({});
const sending = ref(false);
const error = ref('');
watch(() => [s.value.index, s.value.phase], ([, phase], old) => {
    if (phase === 'question' && (old?.[1] !== 'question' || old?.[0] !== s.value.index)) {
        picked.value = []; text.value = ''; pairs.value = {}; error.value = '';
    }
});

const send = async (response) => {
    if (sending.value || me.value.answered) return;
    sending.value = true;
    error.value = '';
    try {
        await postJson(route('live.answer', s.value.id), { question_id: q.value.id, response });
        state.value = { ...state.value, me: { ...me.value, answered: true, response } };
        if (navigator.vibrate) navigator.vibrate(30);
    } catch (e) {
        error.value = e.message;
        refresh();
    } finally {
        sending.value = false;
    }
};
const tap = (o) => {
    if (q.value.type === 'mcm') {
        picked.value = picked.value.includes(o.id) ? picked.value.filter((x) => x !== o.id) : [...picked.value, o.id];
    } else {
        send(o.id);
    }
};
const matchDone = computed(() => q.value?.type === 'match' && Object.keys(pairs.value).length === q.value.lefts.length);

const result = computed(() => me.value.result);
watch(() => [s.value.phase, result.value?.correct], ([phase, ok]) => {
    if (phase === 'reveal' && ok) confetti({ duration: 1800, count: 90 });
    if (phase === 'podium') setTimeout(refreshGamify, 1200);
    if (phase === 'podium' && me.value.rank && me.value.rank <= 3 && me.value.score > 0) setTimeout(() => confetti({ duration: 3500, count: 180 }), 400);
}, { immediate: true });

const cheer = computed(() => {
    if (!result.value) return 'Waktu habis!';
    if (result.value.correct) return ['Mantap!', 'Keren!', 'Tepat sekali!', 'Hebat!'][s.value.index % 4];
    if (result.value.live_score > 0) return 'Hampir sempurna!';
    return 'Coba lagi di soal berikutnya!';
});
const teamColor = (t) => TEAM_COLORS[(t - 1) % TEAM_COLORS.length];
const leave = () => router.post(route('logout'));
</script>

<template>
    <Head :title="s.title" />
    <div class="flex min-h-dvh flex-col bg-bg text-ink">
        <Flash />
        <header class="flex items-center gap-2 border-b border-line/60 px-4 py-2.5">
            <!-- Jalan keluar selalu ada; percobaan tetap tersimpan dan bisa dilanjutkan dari beranda -->
            <Link v-if="page.props.auth.user.role !== 'guest'" :href="route('home')" class="grid size-9 shrink-0 place-items-center rounded-full bg-surface2 text-lg" aria-label="Ke beranda" data-testid="live-home">🏠</Link>
            <span class="min-w-0 flex-1 truncate font-bold">{{ me.name }}</span>
            <span v-if="me.team" class="rounded-full px-2.5 py-0.5 text-xs font-bold text-black" :style="{ background: teamColor(me.team) }">Tim {{ me.team_name }}</span>
            <span class="rounded-full bg-surface2 px-3 py-0.5 font-mono text-sm font-bold" data-testid="my-score">{{ fmtScore(me.score) }}</span>
        </header>
        <p v-if="!online" class="bg-warning px-4 py-1.5 text-center text-sm font-bold text-black">Koneksi terputus, mencoba tersambung lagi…</p>

        <!-- Dikeluarkan -->
        <main v-if="me.kicked" class="m-auto max-w-sm p-6 text-center">
            <Mascot :size="96" mood="sad" class="mx-auto" />
            <p class="mt-3 text-xl font-bold">Kamu dikeluarkan dari kuis ini oleh guru.</p>
        </main>

        <!-- Lobi -->
        <main v-else-if="s.phase === 'lobby'" class="m-auto max-w-sm p-6 text-center">
            <Mascot :size="110" class="mx-auto" />
            <h1 class="mt-3 font-display text-3xl font-bold">Kamu masuk! 🎉</h1>
            <p class="mt-1 text-lg font-semibold text-muted">Lihat layar guru. Kuis dimulai sebentar lagi.</p>
            <p class="mt-4 text-sm text-muted">{{ s.players_count }} pemain sudah bergabung</p>
        </main>

        <!-- Soal -->
        <main v-else-if="s.phase === 'question' && q" class="flex flex-1 flex-col gap-3 p-3 sm:p-5">
            <div class="flex items-center justify-between text-sm font-bold text-muted">
                <span>Soal {{ s.index + 1 }}/{{ s.total }}</span>
                <span class="grid size-12 place-items-center rounded-full border-4 font-display text-xl text-ink" :class="secondsLeft <= 5 && !readyLeft ? 'border-danger' : 'border-accent'" data-testid="countdown">{{ readyLeft ? '…' : secondsLeft }}</span>
            </div>
            <!-- Soal baru tampil setelah hitung mundur, bersamaan dengan layar guru -->
            <ReadyCount v-if="readyLeft" :n="readyLeft" />

            <MathHtml v-if="!readyLeft" :html="q.prompt" class="rich rounded-card bg-surface p-4 text-xl font-semibold shadow-card" />
            <div v-if="!readyLeft && q.media.length" class="flex flex-wrap justify-center gap-2">
                <template v-for="m in q.media" :key="m.id"><img v-if="m.kind === 'image'" :src="m.url" :alt="m.name" class="max-h-48 rounded-xl object-contain" /></template>
            </div>

            <template v-if="readyLeft" />

            <div v-else-if="me.answered" class="m-auto max-w-xs text-center" data-testid="answered">
                <p class="text-5xl">📨</p>
                <p class="mt-2 text-2xl font-bold">Jawaban terkirim!</p>
                <p class="text-muted">Tunggu teman lain… ({{ s.answered }}/{{ s.players_count }})</p>
            </div>

            <template v-else>
                <!-- Pilihan ganda / benar-salah / kompleks -->
                <div v-if="choice" class="grid flex-1 grid-cols-2 gap-2.5" :class="q.options.length <= 2 ? 'grid-rows-1' : 'auto-rows-fr'">
                    <button v-for="(o, i) in q.options" :key="o.id" type="button" :disabled="sending"
                        class="flex min-h-28 flex-col items-center justify-center gap-2 rounded-card p-3 text-center font-extrabold leading-snug shadow-card transition active:scale-95"
                        :class="[optClass(o.text), q.type === 'mcm' && picked.includes(o.id) ? 'ring-4 ring-ink ring-offset-2 ring-offset-bg' : '']"
                        :style="{ background: ANSWER_STYLES[i % 6].bg, color: ANSWER_STYLES[i % 6].fg }"
                        :aria-pressed="q.type === 'mcm' ? picked.includes(o.id) : undefined" @click="tap(o)">
                        <AnswerShape :index="i" :size="40" />
                        <MathHtml :html="o.text" plain tag="span" class="line-clamp-4 break-words" />
                    </button>
                </div>
                <Btn v-if="q.type === 'mcm'" size="lg" block :disabled="!picked.length" :loading="sending" @click="send(picked)">Kirim {{ picked.length }} pilihan</Btn>

                <form v-else-if="q.type === 'short'" class="mt-auto space-y-2" @submit.prevent="text.trim() && send(text.trim())">
                    <input v-model="text" maxlength="200" autocomplete="off" autofocus placeholder="Ketik jawabanmu" aria-label="Jawaban"
                        class="h-14 w-full rounded-xl border-2 border-line bg-surface px-4 text-xl text-ink focus:border-accent focus:outline-none" />
                    <Btn type="submit" size="lg" block :disabled="!text.trim()" :loading="sending">Kirim</Btn>
                </form>

                <template v-else-if="q.type === 'match'">
                    <MatchAnswer :lefts="q.lefts" :rights="q.rights" v-model="pairs" />
                    <Btn size="lg" block :disabled="!matchDone" :loading="sending" @click="send(pairs)">Kirim</Btn>
                </template>
            </template>
            <p v-if="error" class="text-center font-bold text-danger">{{ error }}</p>
        </main>

        <!-- Hasil soal -->
        <main v-else-if="s.phase === 'reveal' || s.phase === 'leaderboard'" class="m-auto w-full max-w-sm p-6 text-center">
            <template v-if="s.phase === 'reveal'">
                <p class="text-7xl" aria-hidden="true">{{ result?.correct ? '✅' : result ? '🤏' : '⏰' }}</p>
                <h1 class="mt-2 font-display text-4xl font-bold" :class="result?.correct ? 'text-success' : 'text-ink'" data-testid="verdict">{{ cheer }}</h1>
                <p v-if="result" class="mt-1 font-mono text-2xl font-bold">+{{ fmtScore(result.live_score) }}</p>
                <p v-if="s.reveal && q && ['mc', 'tf', 'mcm'].includes(q.type)" class="mt-4 rounded-xl bg-surface2 px-4 py-3 font-semibold">
                    Jawaban benar:
                    <span v-for="(o, i) in q.options.filter((x) => s.reveal.key.includes(x.id))" :key="o.id" class="ml-1 inline-flex items-center gap-1 font-bold">
                        <AnswerShape :index="q.options.indexOf(o)" :size="18" /> <MathHtml :html="o.text" plain tag="span" />
                    </span>
                </p>
                <p v-else-if="s.reveal && q?.type === 'short'" class="mt-4 rounded-xl bg-surface2 px-4 py-3 font-semibold">Jawaban benar: <b>{{ s.reveal.key.join(' / ') }}</b></p>
            </template>
            <template v-else>
                <h1 class="font-display text-3xl font-bold">Peringkatmu</h1>
            </template>
            <p class="mt-5 text-lg font-bold">Peringkat <span class="text-accent text-3xl">{{ me.rank ?? '–' }}</span> dari {{ s.players_count }}</p>
            <p class="text-muted">Skor total {{ fmtScore(me.score) }}</p>
        </main>

        <!-- Akhir -->
        <main v-else-if="s.phase === 'podium'" class="m-auto max-w-sm p-6 text-center">
            <p class="text-7xl" aria-hidden="true">{{ me.score > 0 && me.rank === 1 ? '🥇' : me.score > 0 && me.rank === 2 ? '🥈' : me.score > 0 && me.rank === 3 ? '🥉' : '🎉' }}</p>
            <h1 class="mt-2 font-display text-3xl font-bold">{{ me.rank && me.rank <= 3 && me.score > 0 ? 'Kamu juara ' + me.rank + '!' : 'Kuis selesai!' }}</h1>
            <p class="mt-2 text-lg">Peringkat <b>{{ me.rank ?? '–' }}</b> dari {{ s.players_count }} · skor <b>{{ fmtScore(me.score) }}</b></p>
            <p v-if="s.leaderboard?.teams?.length" class="mt-3 font-semibold">Tim juara: <b>{{ s.leaderboard.teams[0].name }}</b></p>
            <Btn v-if="page.props.auth.user.role === 'guest'" class="mt-6" variant="soft" @click="leave">Keluar</Btn>
            <Btn v-else class="mt-6" variant="soft" :href="route('home')">Kembali ke beranda</Btn>
        </main>
    </div>
</template>
