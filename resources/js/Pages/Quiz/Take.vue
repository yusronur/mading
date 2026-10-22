<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { applyTheme } from '@/lib/theme';
import Btn from '@/Components/Btn.vue';
import Flash from '@/Components/Flash.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import MediaViewer from '@/Components/Board/MediaViewer.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import MatchAnswer from '@/Components/Quiz/MatchAnswer.vue';
import { askConfirm } from '@/lib/confirm';
import { postJson } from '@/lib/http';
import { toast } from '@/lib/toast';

// Lembar kerja kuis mandiri: satu soal per layar, simpan otomatis, waktu dari server.
const props = defineProps({ session: Object, attempt: Object, questions: Array });
const page = usePage();

const idx = ref(0);
const q = computed(() => props.questions[idx.value]);
const answers = reactive(Object.fromEntries(props.questions.map((x) => [x.id, x.response ?? (x.type === 'mcm' ? [] : x.type === 'short' ? '' : x.type === 'match' ? {} : null)])));
const state = reactive({}); // id -> 'saving' | 'saved' | 'error'
const viewing = ref(null);
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const SHAPES = ['▲', '◆', '●', '■', '★', '✚']; // bentuk + huruf, bukan hanya warna
const COLORS = ['bg-accent', 'bg-accent2', 'bg-accent3', 'bg-warning', 'bg-success', 'bg-danger'];

const isAnswered = (x) => {
    const a = answers[x.id];
    if (a && typeof a === 'object') return Object.keys(a).length > 0;
    return a !== null && a !== '';
};
const answeredCount = computed(() => props.questions.filter(isAnswered).length);

// ---------- Simpan otomatis (antre bila koneksi putus)
const pending = new Map();
let flushing = false;
let submitted = false;
async function flush() {
    if (flushing) return;
    flushing = true;
    try {
        for (const [id, response] of [...pending]) {
            state[id] = 'saving';
            try {
                await postJson(route('take.answer', props.attempt.id), { question_id: id, response });
                if (pending.get(id) === response) pending.delete(id);
                state[id] = 'saved';
            } catch (e) {
                if (e.status === 422 && e.data?.submitted) {
                    submitted = true;
                    toast(e.message, 'error');
                    router.visit(route('take.result', props.attempt.id));
                    return;
                }
                if (e.status && e.status < 500 && e.status !== 419 && e.status !== 429) {
                    pending.delete(id);
                    toast(e.message, 'error');
                }
                state[id] = 'error';
                break; // koneksi bermasalah: coba lagi nanti
            }
        }
    } finally {
        flushing = false;
    }
}
const save = (id) => {
    const v = answers[id];
    pending.set(id, Array.isArray(v) ? [...v] : v && typeof v === 'object' ? { ...v } : v);
    state[id] = 'saving';
    flush();
};
let typing;
const saveSoon = (id) => {
    clearTimeout(typing);
    typing = setTimeout(() => save(id), 700);
};
const retry = setInterval(() => pending.size && flush(), 4000);

const choose = (opt) => {
    const id = q.value.id;
    if (q.value.multiple) {
        const cur = answers[id] || [];
        answers[id] = cur.includes(opt.id) ? cur.filter((x) => x !== opt.id) : [...cur, opt.id];
    } else {
        answers[id] = answers[id] === opt.id ? null : opt.id;
    }
    save(id);
};
const setMatch = (id, map) => {
    answers[id] = map;
    save(id);
};
const isChosen = (opt) => (q.value.multiple ? (answers[q.value.id] || []).includes(opt.id) : answers[q.value.id] === opt.id);

// ---------- Waktu (dikoreksi dengan jam server)
const offset = new Date(props.attempt.server_now).getTime() - Date.now();
const remaining = ref(null);
let ticker;
const tick = () => {
    if (!props.attempt.deadline_at) return;
    remaining.value = Math.max(0, Math.floor((new Date(props.attempt.deadline_at).getTime() - (Date.now() + offset)) / 1000));
    if (remaining.value === 60) toast('Sisa waktu 1 menit!', 'error');
    if (remaining.value === 0 && !submitted) doSubmit(true);
};
const clock = computed(() => {
    if (remaining.value === null) return null;
    const h = Math.floor(remaining.value / 3600), m = Math.floor((remaining.value % 3600) / 60), s = remaining.value % 60;
    return (h ? `${h}:` : '') + `${String(m).padStart(h ? 2 : 1, '0')}:${String(s).padStart(2, '0')}`;
});

// ---------- Keluar halaman (dicatat untuk guru)
const onVisibility = () => {
    if (document.hidden && !submitted) postJson(route('take.focus', props.attempt.id)).catch(() => {});
    if (!document.hidden) flush();
};
onMounted(() => {
    applyTheme(page.props.theme);
    tick();
    ticker = setInterval(tick, 1000);
    document.addEventListener('visibilitychange', onVisibility);
});
onBeforeUnmount(() => {
    clearInterval(ticker);
    clearInterval(retry);
    document.removeEventListener('visibilitychange', onVisibility);
});

// ---------- Kumpulkan
const sending = ref(false);
async function doSubmit(auto = false) {
    if (submitted) return;
    if (!auto) {
        const empty = props.questions.length - answeredCount.value;
        const ok = await askConfirm({
            title: 'Kumpulkan jawaban?',
            message: empty ? `Masih ada ${empty} soal kosong. Setelah dikumpulkan, jawaban tidak bisa diubah.` : 'Setelah dikumpulkan, jawaban tidak bisa diubah.',
            confirmText: 'Kumpulkan',
            tone: empty ? 'danger' : 'primary',
        });
        if (!ok) return;
    }
    sending.value = true;
    clearTimeout(typing);
    for (const x of props.questions) if (x.type === 'short' && state[x.id] === undefined && answers[x.id]) pending.set(x.id, answers[x.id]);
    await flush();
    submitted = true;
    if (auto) toast('Waktu habis, jawaban dikumpulkan otomatis.', 'error');
    router.post(route('take.submit', props.attempt.id), {}, { onFinish: () => (sending.value = false) });
}
const go = (i) => {
    if (q.value.type === 'short' && pending.has(q.value.id)) { clearTimeout(typing); flush(); }
    idx.value = Math.min(Math.max(0, i), props.questions.length - 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
</script>

<template>
    <Head :title="session.title" />
    <Flash />
    <ConfirmDialog />
    <div class="mx-auto flex min-h-dvh max-w-3xl flex-col px-4 pb-28">
        <!-- Bilah atas -->
        <header class="sticky top-0 z-20 -mx-4 mb-4 flex items-center gap-3 border-b border-line/70 bg-bg/90 px-4 py-3 backdrop-blur">
            <p class="min-w-0 flex-1 truncate font-display font-bold">{{ session.title }}</p>
            <span class="text-sm font-semibold text-muted">{{ answeredCount }}/{{ questions.length }}</span>
            <span v-if="clock" class="rounded-xl px-3 py-1 font-mono text-lg font-bold" :class="remaining <= 60 ? 'animate-pulse bg-danger text-bg' : remaining <= 300 ? 'bg-warning/20 text-warning' : 'bg-surface2'" role="timer" aria-live="off">⏱ {{ clock }}</span>
        </header>

        <!-- Navigasi nomor -->
        <nav class="mb-5 flex flex-wrap gap-1.5" aria-label="Nomor soal">
            <button v-for="(x, i) in questions" :key="x.id" type="button" class="grid size-9 place-items-center rounded-lg text-sm font-bold transition"
                :class="[i === idx ? 'ring-2 ring-accent' : '', isAnswered(x) ? 'bg-accent text-on-accent' : 'bg-surface2 text-muted', state[x.id] === 'error' ? 'ring-2 ring-danger' : '']"
                :aria-current="i === idx" :aria-label="`Soal ${i + 1}${isAnswered(x) ? ', sudah dijawab' : ''}`" @click="go(i)">{{ i + 1 }}</button>
        </nav>

        <main v-if="q" :key="q.id" class="animate-pop flex-1">
            <p class="mb-2 text-sm font-bold text-muted">Soal {{ idx + 1 }} dari {{ questions.length }} · {{ q.points }} poin
                <span v-if="q.type === 'mcm'" class="text-accent"> · pilih semua yang benar</span>
                <span v-if="q.type === 'match'" class="text-accent"> · jodohkan setiap pasangan</span></p>
            <MathHtml :html="q.prompt" class="rich text-lg sm:text-xl" />
            <div v-if="q.media.length" class="mt-3 flex flex-wrap gap-2">
                <template v-for="m in q.media" :key="m.id">
                    <button v-if="m.kind === 'image'" type="button" class="overflow-hidden rounded-xl" @click="viewing = m"><img :src="m.url" :alt="m.name" class="max-h-72 w-auto max-w-full object-contain" /></button>
                    <audio v-else-if="m.kind === 'audio'" :src="m.url" controls class="w-full"></audio>
                    <video v-else :src="m.url" controls playsinline class="max-h-72 w-full rounded-xl"></video>
                </template>
            </div>

            <div v-if="q.options" class="mt-5 grid gap-2.5 sm:grid-cols-2">
                <button v-for="(o, i) in q.options" :key="o.id" type="button" class="flex min-h-16 items-center gap-3 rounded-2xl border-2 p-3 text-left transition active:scale-[0.98]"
                    :class="isChosen(o) ? 'border-accent bg-accent/15 shadow-card' : 'border-line bg-surface hover:border-accent/50'" :aria-pressed="isChosen(o)" @click="choose(o)">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl font-display text-lg font-bold text-bg" :class="COLORS[i % 6]">
                        <span v-if="isChosen(o)">✓</span><span v-else>{{ q.type === 'tf' ? (o.id === 'true' ? '✓' : '✗') : LETTERS[i] }}</span>
                    </span>
                    <MathHtml :html="o.text" plain tag="span" class="min-w-0 flex-1 text-base font-semibold sm:text-lg" />
                    <span class="text-xs opacity-40" aria-hidden="true">{{ SHAPES[i % 6] }}</span>
                </button>
            </div>

            <MatchAnswer v-else-if="q.type === 'match'" class="mt-5" :lefts="q.lefts" :rights="q.rights" :model-value="answers[q.id]" @update:model-value="setMatch(q.id, $event)" />

            <div v-else class="mt-5">
                <label :for="`ans-${q.id}`" class="mb-1.5 block text-sm font-bold">Jawabanmu</label>
                <input :id="`ans-${q.id}`" v-model="answers[q.id]" maxlength="200" autocomplete="off" placeholder="Ketik jawaban singkat…"
                    class="h-14 w-full rounded-2xl border-2 border-line bg-surface px-4 text-lg text-ink focus:border-accent focus:outline-none"
                    @input="saveSoon(q.id)" @blur="answers[q.id] !== undefined && save(q.id)" @keydown.enter.prevent="save(q.id); go(idx + 1)" />
            </div>

            <p class="mt-3 h-5 text-right text-xs font-semibold" :class="state[q.id] === 'error' ? 'text-danger' : 'text-muted'">
                {{ { saving: 'Menyimpan…', saved: 'Tersimpan ✓', error: 'Belum tersimpan, mencoba lagi…' }[state[q.id]] ?? '' }}
            </p>
        </main>

        <!-- Bilah bawah -->
        <footer class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center gap-2 px-4 py-3">
                <Btn variant="soft" :disabled="idx === 0" @click="go(idx - 1)">←</Btn>
                <Btn v-if="idx < questions.length - 1" class="flex-1" @click="go(idx + 1)">Berikutnya →</Btn>
                <Btn v-else class="flex-1" :loading="sending" @click="doSubmit()">Kumpulkan ✅</Btn>
                <Btn v-if="idx < questions.length - 1" variant="ghost" :loading="sending" @click="doSubmit()">Kumpulkan</Btn>
            </div>
        </footer>
        <MediaViewer :item="viewing" @close="viewing = null" />
    </div>
</template>
