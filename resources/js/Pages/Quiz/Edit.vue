<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import { getJson } from '@/lib/http';
import { toast } from '@/lib/toast';

// Penyusun kuis: pilih soal dari bank, urutkan, atur poin dan pengaturan (QZ-08).
const props = defineProps({ quiz: Object, questions: Array, banks: Array, types: Object, difficulties: Object });

const form = useForm({
    title: props.quiz.title,
    description: props.quiz.description ?? '',
    settings: { ...props.quiz.settings },
    questions: [],
});
const list = ref(props.questions.map((q) => ({ ...q })));
const total = computed(() => list.value.reduce((s, q) => s + (q.quiz_points || q.points), 0));

const move = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= list.value.length) return;
    const arr = list.value;
    [arr[i], arr[j]] = [arr[j], arr[i]];
};
const removeAt = (i) => list.value.splice(i, 1);

const save = () => {
    form.transform((d) => ({
        ...d,
        settings: { ...d.settings, duration_minutes: Number(d.settings.duration_minutes) || null },
        questions: list.value.map((q) => ({ id: q.id, points: q.quiz_points || null })),
    })).put(route('quizzes.update', props.quiz.id), { preserveScroll: true });
};

// ---------- Pemilih soal
const pickerOpen = ref(false);
const bankId = ref((props.banks.find((b) => b.mine) ?? props.banks[0])?.id ?? null);
const pool = ref([]);
const picked = ref([]);
const loading = ref(false);
const chosen = computed(() => new Set(list.value.map((q) => q.id)));
const bankQ = ref('');
const qQ = ref('');
const qType = ref('');
const bankHits = computed(() => {
    const t = bankQ.value.trim().toLowerCase();
    return props.banks.filter((b) => !t || `${b.name} ${b.subject ?? ''} ${b.grade ? 'kelas ' + b.grade : ''} ${b.owner ?? ''}`.toLowerCase().includes(t));
});
const currentBank = computed(() => props.banks.find((b) => b.id === bankId.value));
const poolHits = computed(() => {
    const t = qQ.value.trim().toLowerCase();
    return pool.value.filter((q) => (!qType.value || q.type === qType.value) && (!t || strip(q.prompt).toLowerCase().includes(t) || (q.tags || []).some((x) => x.toLowerCase().includes(t))));
});
const chooseBank = (id) => { bankId.value = id; qQ.value = ''; picked.value = []; load(); };
const load = async () => {
    if (!bankId.value) return;
    loading.value = true;
    try { pool.value = await getJson(route('quizzes.picker', bankId.value)); } catch (e) { toast(e.message, 'error'); } finally { loading.value = false; }
};
const openPicker = () => { picked.value = []; qQ.value = ''; qType.value = ''; pickerOpen.value = true; load(); };
const togglePick = (q) => (picked.value = picked.value.includes(q.id) ? picked.value.filter((x) => x !== q.id) : [...picked.value, q.id]);
const pickAll = () => (picked.value = poolHits.value.filter((q) => !chosen.value.has(q.id)).map((q) => q.id));
const addPicked = () => {
    pool.value.filter((q) => picked.value.includes(q.id)).forEach((q) => list.value.push({ ...q, quiz_points: null }));
    pickerOpen.value = false;
    toast(`${picked.value.length} soal ditambahkan. Jangan lupa Simpan.`);
};
const typeIcon = { mc: '🔘', mcm: '☑️', tf: '⚖️', short: '✏️', match: '🔗' };
const strip = (h) => (h || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
</script>

<template>
    <Head :title="quiz.title" />
    <AppLayout wide :back="{ href: route('quizzes.index'), label: 'Kuis' }">
        <div class="mb-5 flex flex-wrap items-center justify-end gap-3">
            <Btn size="lg" :loading="form.processing" @click="save">💾 Simpan kuis</Btn>
        </div>
        <p v-if="quiz.assigned" class="mb-4 rounded-xl bg-warning/15 px-4 py-2 text-sm font-semibold text-warning">Kuis ini sudah ditugaskan. Perubahan hanya berlaku untuk penugasan berikutnya.</p>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <section class="min-w-0 space-y-4">
                <Field v-model="form.title" label="Judul" maxlength="150" :error="form.errors.title" />
                <Field v-model="form.description" as="textarea" :rows="2" label="Petunjuk untuk siswa (opsional)" maxlength="1000" />

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-xl font-bold">Soal ({{ list.length }}) · {{ total }} poin</h2>
                    <Btn size="sm" :disabled="!banks.length" @click="openPicker">+ Ambil dari bank soal</Btn>
                </div>
                <EmptyState v-if="!list.length" title="Belum ada soal" :text="banks.length ? 'Ambil soal dari bank soal Anda.' : 'Buat bank soal dan isi soalnya dulu.'">
                    <Btn v-if="!banks.length" :href="route('banks.index')">Buka bank soal</Btn>
                </EmptyState>
                <ol v-else class="space-y-2">
                    <li v-for="(q, i) in list" :key="q.id" class="flex gap-3 rounded-card border border-line bg-surface p-3">
                        <div class="flex flex-col items-center gap-1">
                            <span class="grid size-8 place-items-center rounded-full bg-ink text-sm font-bold text-bg">{{ i + 1 }}</span>
                            <button class="text-muted hover:text-ink disabled:opacity-30" :disabled="i === 0" aria-label="Naikkan" @click="move(i, -1)">▲</button>
                            <button class="text-muted hover:text-ink disabled:opacity-30" :disabled="i === list.length - 1" aria-label="Turunkan" @click="move(i, 1)">▼</button>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="mb-1 text-xs font-bold text-muted">{{ typeIcon[q.type] }} {{ q.type_label }} · {{ difficulties[q.difficulty] }}<span v-if="q.media.length"> · 🖼️{{ q.media.length }}</span></p>
                            <MathHtml :html="q.prompt" class="rich line-clamp-3" />
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <label class="flex items-center gap-1 text-sm"><input v-model.number="q.quiz_points" type="number" min="1" max="100" :placeholder="q.points" class="h-9 w-16 rounded-lg border-2 border-line bg-surface px-2 text-ink" aria-label="Poin" /> poin</label>
                            <button class="text-sm font-bold text-muted hover:text-danger" @click="removeAt(i)">Keluarkan</button>
                        </div>
                    </li>
                </ol>
            </section>

            <aside class="space-y-4 rounded-card border border-line bg-surface p-4 lg:sticky lg:top-20 lg:self-start">
                <h2 class="text-lg font-bold">Pengaturan</h2>
                <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.settings.shuffle_questions" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Acak urutan soal</label>
                <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.settings.shuffle_options" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Acak urutan pilihan</label>
                <div>
                    <p class="text-sm font-semibold">Durasi mengerjakan <span class="text-danger">*</span></p>
                    <label class="mt-1 flex items-center gap-2 text-sm"><input v-model.number="form.settings.duration_minutes" type="number" min="1" max="600" required placeholder="30" class="h-10 w-20 rounded-lg border-2 border-line bg-surface px-2 text-ink" /> menit per percobaan</label>
                    <p class="mt-1 text-xs text-muted">Dihitung sejak siswa menekan Mulai; tiap percobaan mendapat waktu penuh. Tenggat tugas hanya membatasi kapan boleh mulai.</p>
                    <p v-if="form.errors['settings.duration_minutes']" class="text-sm text-danger">{{ form.errors['settings.duration_minutes'] }}</p>
                </div>
                <label class="block text-sm font-bold">Kesempatan mengerjakan
                    <select v-model.number="form.settings.attempts" class="mt-1 h-10 w-full rounded-lg border-2 border-line bg-surface px-2 font-normal text-ink">
                        <option v-for="n in [1, 2, 3, 5]" :key="n" :value="n">{{ n }} kali</option>
                        <option :value="0">Tanpa batas</option>
                    </select>
                </label>
                <label v-if="form.settings.attempts !== 1" class="block text-sm font-bold">Nilai yang dipakai
                    <select v-model="form.settings.scoring" class="mt-1 h-10 w-full rounded-lg border-2 border-line bg-surface px-2 font-normal text-ink">
                        <option value="best">Nilai tertinggi</option><option value="last">Percobaan terakhir</option>
                    </select>
                </label>
                <label class="block text-sm font-bold">Pembahasan & kunci tampil
                    <select v-model="form.settings.review" class="mt-1 h-10 w-full rounded-lg border-2 border-line bg-surface px-2 font-normal text-ink">
                        <option value="after_submit">Setelah siswa mengumpulkan</option>
                        <option value="after_close">Setelah tenggat / ditutup</option>
                        <option value="never">Tidak ditampilkan</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.settings.show_score" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Siswa melihat nilainya</label>
                <label class="flex items-center gap-2 text-sm font-bold">KKM <input v-model.number="form.settings.kkm" type="number" min="0" max="100" class="h-10 w-20 rounded-lg border-2 border-line bg-surface px-2 font-normal text-ink" /></label>
                <Btn block :loading="form.processing" @click="save">💾 Simpan</Btn>
            </aside>
        </div>

        <Modal :show="pickerOpen" title="Ambil soal dari bank" wide @close="pickerOpen = false">
            <div class="space-y-3">
                <div>
                    <input v-model="bankQ" type="search" placeholder="Cari bank soal (nama, mapel, kelas, guru)…" aria-label="Cari bank soal"
                        class="h-11 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                    <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-xl border border-line p-1" role="listbox" aria-label="Bank soal">
                        <li v-for="b in bankHits" :key="b.id">
                            <button type="button" role="option" :aria-selected="b.id === bankId" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition"
                                :class="b.id === bankId ? 'bg-accent text-on-accent' : 'hover:bg-surface2'" @click="chooseBank(b.id)">
                                <span class="min-w-0 flex-1 truncate font-bold">{{ b.name }}</span>
                                <span class="shrink-0 text-xs opacity-80">{{ [b.mine ? null : b.owner, b.subject, b.grade ? 'Kls ' + b.grade : null].filter(Boolean).join(' · ') }} · {{ b.questions_count }}</span>
                            </button>
                        </li>
                        <li v-if="!bankHits.length" class="px-3 py-3 text-center text-sm text-muted">{{ banks.length ? 'Tidak ada bank yang cocok.' : 'Belum ada bank soal.' }}</li>
                    </ul>
                </div>
                <div v-if="currentBank" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <input v-model="qQ" type="search" placeholder="Cari soal di bank ini (teks atau topik)…" aria-label="Cari soal" class="h-11 rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                    <select v-model="qType" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Tipe soal"><option value="">Semua tipe</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                </div>
                <div class="flex items-center justify-between text-sm"><span class="text-muted">{{ loading ? 'Memuat…' : `${picked.length} dipilih · ${poolHits.length} soal ditampilkan` }}</span><button class="font-bold" @click="pickAll">Pilih semua yang tampil</button></div>
                <ul class="max-h-[55dvh] space-y-1.5 overflow-y-auto">
                    <li v-for="q in poolHits" :key="q.id">
                        <label class="flex cursor-pointer gap-3 rounded-xl border-2 p-3" :class="chosen.has(q.id) ? 'opacity-50 border-transparent' : picked.includes(q.id) ? 'border-accent bg-surface2' : 'border-line'">
                            <input type="checkbox" class="mt-1 size-5 shrink-0 accent-[var(--m-accent)]" :disabled="chosen.has(q.id)" :checked="picked.includes(q.id) || chosen.has(q.id)" @change="togglePick(q)" />
                            <span class="min-w-0 text-sm">
                                <Badge tone="neutral">{{ typeIcon[q.type] }} {{ q.type_label }}</Badge> <span class="text-xs text-muted">{{ q.points }} poin{{ chosen.has(q.id) ? ' · sudah di kuis' : '' }}</span>
                                <span class="mt-1 line-clamp-2 block">{{ strip(q.prompt) }}</span>
                            </span>
                        </label>
                    </li>
                    <li v-if="!loading && !poolHits.length" class="py-6 text-center text-muted">{{ pool.length ? 'Tidak ada soal yang cocok.' : 'Bank ini belum berisi soal.' }}</li>
                </ul>
                <Btn block :disabled="!picked.length" @click="addPicked">Tambahkan {{ picked.length }} soal</Btn>
            </div>
        </Modal>
    </AppLayout>
</template>
