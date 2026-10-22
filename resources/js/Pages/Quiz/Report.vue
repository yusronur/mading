<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import DateField from '@/Components/DateField.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import QuizShareDialog from '@/Components/Quiz/QuizShareDialog.vue';
import { askConfirm } from '@/lib/confirm';
import { getJson } from '@/lib/http';
import { fmtDateTime, toLocalInput, untilText } from '@/lib/datetime';

// Laporan tugas kuis (QZ-14, QZ-15): nilai, analisis butir, remedial.
const props = defineProps({ session: Object, report: Object });
const tab = ref('grades');
const s = computed(() => props.session);
const r = computed(() => props.report);

// ---------- Pantau langsung: nilai & kemajuan tiap siswa selama kuis berjalan
const live = ref({ rows: props.report.rows, summary: props.report.summary });
watch(() => props.report, (v) => (live.value = { rows: v.rows, summary: v.summary }));
const rows = computed(() => live.value.rows);
const summary = computed(() => live.value.summary);
const lastSeen = ref(null); // waktu pembaruan terakhir
const fresh = ref({}); // id siswa -> baru berubah (disorot sebentar)
const isLive = computed(() => s.value.status === 'open');
let timer;
let failures = 0;
let doneCount = props.report.summary.done;

const poll = async () => {
    clearTimeout(timer);
    if (document.hidden || !isLive.value) return;
    try {
        const p = await getJson(route('assignments.progress', s.value.id));
        failures = 0;
        const before = new Map(live.value.rows.map((x) => [x.id, `${x.status}|${x.answered}|${x.score_now}|${x.grade}`]));
        for (const x of p.rows) {
            if (before.has(x.id) && before.get(x.id) !== `${x.status}|${x.answered}|${x.score_now}|${x.grade}`) {
                fresh.value = { ...fresh.value, [x.id]: true };
                setTimeout(() => { const { [x.id]: _, ...rest } = fresh.value; fresh.value = rest; }, 1600);
            }
        }
        live.value = { rows: p.rows, summary: p.summary };
        lastSeen.value = new Date();
        // Ada yang baru selesai, atau status tugas berubah: segarkan analisis butir & remedial
        if (p.summary.done !== doneCount || p.status !== s.value.status) {
            doneCount = p.summary.done;
            router.reload({ only: ['report', 'session'], preserveScroll: true, preserveState: true });
        }
    } catch (e) {
        failures = Math.min(failures + 1, 4);
    }
    timer = setTimeout(poll, failures ? 3000 * 2 ** failures : 3000);
};
const onVisible = () => !document.hidden && poll();
onMounted(() => {
    poll();
    document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisible);
});
const pct = (row) => (row.total ? Math.round(((row.answered ?? 0) / row.total) * 100) : 0);

const close = async () => {
    if (await askConfirm({ title: 'Tutup kuis sekarang?', message: 'Siswa yang sedang mengerjakan langsung dikumpulkan otomatis.', confirmText: 'Tutup', tone: 'danger' })) {
        router.put(route('assignments.update', s.value.id), { action: 'close' }, { preserveScroll: true });
    }
};
const extendOpen = ref(false);
const shareOpen = ref(false);
const ef = useForm({ action: 'extend', closes_at: '' });
const openExtend = () => {
    ef.closes_at = toLocalInput(new Date(Math.max(Date.now(), new Date(s.value.closes_at || Date.now()).getTime()) + 86400e3));
    ef.action = s.value.status === 'closed' ? 'reopen' : 'extend';
    extendOpen.value = true;
};
const extend = () => ef.transform((d) => ({ ...d, closes_at: d.closes_at.replace('T', ' ') })).put(route('assignments.update', s.value.id), { preserveScroll: true, onSuccess: () => (extendOpen.value = false) });
const reset = async (row) => {
    if (await askConfirm({ title: `Izinkan ${row.name} mengerjakan ulang?`, message: 'Semua pekerjaan dan nilainya pada tugas ini dihapus.', confirmText: 'Hapus & izinkan ulang', tone: 'danger' })) {
        router.post(route('assignments.reset', [s.value.id, row.id]), {}, { preserveScroll: true });
    }
};
const removeSession = async () => {
    if (await askConfirm({ title: 'Hapus tugas ini?', message: 'Semua nilai pada tugas ini ikut hilang dari laporan.', confirmText: 'Hapus tugas', tone: 'danger' })) {
        router.delete(route('assignments.destroy', s.value.id));
    }
};

const statusTone = { done: 'success', working: 'warning', none: 'neutral' };
const statusLabel = { done: 'Selesai', working: 'Mengerjakan', none: 'Belum' };
const minutes = (sec) => (sec == null ? '–' : sec < 60 ? `${sec} dtk` : `${Math.round(sec / 60)} mnt`);
const pctTone = (p) => (p === null ? 'bg-surface2' : p >= 0.7 ? 'bg-success' : p >= 0.3 ? 'bg-warning' : 'bg-danger');
const sessionStatus = { open: ['success', 'Berjalan'], scheduled: ['warning', 'Terjadwal'], closed: ['neutral', 'Ditutup'] };
</script>

<template>
    <Head :title="`Laporan ${s.title}`" />
    <AppLayout wide :back="{ href: route('quizzes.index'), label: 'Kuis' }">
        <section class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-bold text-muted">{{ s.has_class ? "Rombel " : "" }}{{ s.class_name }} · {{ s.questions }} soal · {{ s.max_score }} poin</p>
                <h1 class="text-3xl font-bold">{{ s.title }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <Badge :tone="sessionStatus[s.status][0]">{{ sessionStatus[s.status][1] }}</Badge>
                    <span v-if="isLive" class="inline-flex items-center gap-1.5 rounded-full bg-success/15 px-2.5 py-0.5 text-xs font-bold text-success" :title="lastSeen ? 'Diperbarui ' + lastSeen.toLocaleTimeString('id-ID') : ''"><span class="size-2 animate-pulse rounded-full bg-success"></span>Langsung</span>
                    <span class="text-muted">Tenggat {{ fmtDateTime(s.closes_at) }}<span v-if="s.status === 'open'"> ({{ untilText(s.closes_at) }})</span></span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-2xl border-2 border-dashed border-line px-4 py-1 text-center transition active:scale-95" title="Bagikan kuis" @click="shareOpen = true">
                    <span class="block text-xs font-bold text-muted uppercase">Kode kuis</span>
                    <span class="font-mono text-2xl font-bold tracking-[0.25em]">{{ s.code }}</span>
                </button>
                <a :href="route('assignments.export', s.id)" class="inline-flex h-11 items-center rounded-[calc(var(--m-radius)*0.7)] bg-accent px-5 font-display font-semibold text-on-accent shadow-card">📊 Ekspor Excel</a>
                <Btn variant="soft" @click="openExtend">{{ s.status === 'closed' ? 'Buka lagi' : 'Ubah tenggat' }}</Btn>
                <Btn v-if="s.status !== 'closed'" variant="soft" @click="close">Tutup sekarang</Btn>
                <Btn variant="ghost" aria-label="Hapus tugas" @click="removeSession">🗑️</Btn>
            </div>
        </section>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <Card><p class="font-display text-3xl font-bold">{{ summary.done }}<span class="text-lg text-muted">/{{ summary.students }}</span></p><p class="text-sm font-semibold text-muted">sudah mengumpulkan</p></Card>
            <Card><p class="font-display text-3xl font-bold text-accent">{{ summary.average ?? '–' }}</p><p class="text-sm font-semibold text-muted">rata-rata</p></Card>
            <Card><p class="font-display text-3xl font-bold">{{ summary.highest ?? '–' }} <span class="text-lg text-muted">/ {{ summary.lowest ?? '–' }}</span></p><p class="text-sm font-semibold text-muted">tertinggi / terendah</p></Card>
            <Card><p class="font-display text-3xl font-bold text-success">{{ summary.passed }}</p><p class="text-sm font-semibold text-muted">tuntas (KKM {{ r.kkm }})</p></Card>
            <Card><p class="font-display text-3xl font-bold text-warning">{{ summary.working }}</p><p class="text-sm font-semibold text-muted">sedang mengerjakan</p></Card>
        </div>

        <div class="mb-4 flex flex-wrap gap-2" role="tablist">
            <button v-for="t in [['grades', 'Nilai siswa'], ['items', 'Analisis butir soal'], ['remedial', `Remedial (${r.remedial.length})`]]" :key="t[0]" role="tab" :aria-selected="tab === t[0]"
                class="rounded-full px-4 py-2 text-sm font-bold" :class="tab === t[0] ? 'bg-ink text-bg' : 'bg-surface2 text-muted hover:text-ink'" @click="tab = t[0]">{{ t[1] }}</button>
        </div>

        <!-- Nilai -->
        <Card v-if="tab === 'grades'" :padded="false" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface2 text-xs uppercase text-muted">
                        <tr><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right">Skor</th><th class="px-4 py-3">Durasi</th><th class="px-4 py-3">Dikumpulkan</th><th class="px-4 py-3" title="Berapa kali meninggalkan halaman kuis">Keluar layar</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.id" class="transition-colors duration-700" :class="fresh[row.id] ? 'bg-accent/15' : ''">
                            <td class="px-4 py-3"><p class="font-bold">{{ row.name }}</p><p v-if="row.guest" class="text-xs font-bold text-accent2">Tamu</p><p v-else class="font-mono text-xs text-muted">{{ row.username }}</p></td>
                            <td class="px-4 py-3">
                                <Badge :tone="statusTone[row.status]">{{ statusLabel[row.status] }}</Badge><span v-if="row.attempts > 1" class="ml-1 text-xs text-muted">{{ row.attempts }}×</span>
                                <div v-if="row.status === 'working'" class="mt-1.5 w-40" :title="`${row.answered} dari ${row.total} soal terjawab`">
                                    <div class="h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full bg-accent transition-all duration-700" :style="{ width: pct(row) + '%' }"></div></div>
                                    <p class="mt-0.5 text-xs text-muted">{{ row.answered }}/{{ row.total }} soal · {{ pct(row) }}%</p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-display text-lg font-bold" :class="row.grade === null ? 'text-muted' : row.passed ? 'text-success' : 'text-danger'">{{ row.status === 'working' ? row.grade_now : (row.grade ?? '–') }}<span v-if="row.status === 'working'" class="block text-[10px] font-semibold tracking-wide text-muted uppercase">sementara</span></td>
                            <td class="px-4 py-3 text-right text-muted">{{ row.status === 'working' ? row.score_now : (row.score ?? '–') }}<span v-if="row.status === 'working'">/{{ s.max_score }}</span><span v-else-if="row.max_score">/{{ row.max_score }}</span></td>
                            <td class="px-4 py-3 text-muted">{{ minutes(row.duration_seconds) }}</td>
                            <td class="px-4 py-3 text-muted">{{ row.submitted_at ? fmtDateTime(row.submitted_at) : '–' }}</td>
                            <td class="px-4 py-3" :class="row.focus_lost > 2 ? 'font-bold text-warning' : 'text-muted'">{{ row.focus_lost || '–' }}</td>
                            <td class="px-4 py-3"><div class="flex justify-end gap-1.5">
                                <Link v-if="row.attempt_id" :href="route('assignments.attempt', [s.id, row.attempt_id])" class="rounded-lg bg-surface2 px-3 py-1.5 text-xs font-bold hover:bg-line/60">Jawaban</Link>
                                <button v-if="row.attempts" class="rounded-lg px-2 py-1.5 text-xs font-bold text-muted hover:text-danger" @click="reset(row)">Ulang</button>
                            </div></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-4 py-10 text-center text-muted">{{ s.has_class ? "Rombel ini belum punya siswa." : "Belum ada siswa yang memulai. Bagikan kode kuisnya." }}</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Analisis butir -->
        <div v-else-if="tab === 'items'" class="space-y-3">
            <p class="text-sm text-muted">Indeks p = proporsi siswa yang menjawab benar (≥ 0,70 mudah · 0,30–0,69 sedang · &lt; 0,30 sukar). Butir sukar layak dibahas ulang di kelas.</p>
            <Card v-for="it in r.items" :key="it.id">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="grid size-8 place-items-center rounded-full bg-ink text-sm font-bold text-bg">{{ it.no }}</span>
                    <Badge :tone="it.level === 'Sukar' ? 'danger' : it.level === 'Sedang' ? 'warning' : it.level ? 'success' : 'neutral'">{{ it.level ?? 'Belum ada data' }}</Badge>
                    <span class="text-sm text-muted">p = {{ it.p ?? '–' }} · {{ it.correct }}/{{ it.answered }} benar · {{ it.points }} poin</span>
                </div>
                <MathHtml :html="it.prompt" class="rich mb-3 line-clamp-4" />
                <div class="h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full" :class="pctTone(it.p)" :style="{ width: `${(it.p ?? 0) * 100}%` }"></div></div>
                <ul v-if="it.distribution.length" class="mt-3 space-y-1">
                    <li v-for="d in it.distribution" :key="d.label" class="flex items-center gap-2 text-sm">
                        <span class="w-24 shrink-0 truncate font-bold" :class="d.correct ? 'text-success' : ''">{{ d.correct ? '✓ ' : '' }}{{ d.label }}</span>
                        <span class="h-3 rounded bg-accent/60" :style="{ width: `${it.answered ? (d.count / Math.max(1, it.answered)) * 60 : 0}%`, minWidth: d.count ? '4px' : '0' }"></span>
                        <span class="text-muted">{{ d.count }}</span>
                        <span v-if="d.text" class="truncate text-xs text-muted">{{ d.text }}</span>
                    </li>
                </ul>
                <p v-if="it.type === 'short'" class="mt-2 text-xs text-muted">Kunci: {{ it.key.join(' / ') }}{{ it.distribution.length ? ' · di atas: jawaban salah terbanyak' : '' }}</p>
            </Card>
        </div>

        <!-- Remedial -->
        <Card v-else>
            <p class="mb-3 text-sm text-muted">Siswa dengan nilai di bawah KKM {{ r.kkm }} atau belum mengumpulkan.</p>
            <ul class="divide-y divide-line">
                <li v-for="row in r.remedial" :key="row.id" class="flex items-center justify-between gap-3 py-2">
                    <span><span class="font-bold">{{ row.name }}</span> <span v-if="row.guest" class="text-xs font-bold text-accent2">Tamu</span><span v-else class="font-mono text-xs text-muted">{{ row.username }}</span></span>
                    <span class="font-display font-bold" :class="row.grade === null ? 'text-muted' : 'text-danger'">{{ row.grade ?? statusLabel[row.status] }}</span>
                </li>
                <li v-if="!r.remedial.length" class="py-6 text-center font-bold text-success">Semua siswa tuntas 🎉</li>
            </ul>
        </Card>

        <Modal :show="extendOpen" :title="s.status === 'closed' ? 'Buka lagi kuis' : 'Ubah tenggat'" @close="extendOpen = false">
            <form class="space-y-4" @submit.prevent="extend">
                <DateField v-model="ef.closes_at" mode="datetime" label="Tenggat baru" required :error="ef.errors.closes_at" :min="toLocalInput(new Date()).slice(0, 10)" />
                <Btn type="submit" block :loading="ef.processing">Simpan</Btn>
            </form>
        </Modal>
        <QuizShareDialog :show="shareOpen" :session="s" @close="shareOpen = false" />
    </AppLayout>
</template>
