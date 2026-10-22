<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MediaViewer from '@/Components/Board/MediaViewer.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import QuestionEditor from '@/Components/Quiz/QuestionEditor.vue';
import ImportDialog from '@/Components/Quiz/ImportDialog.vue';
import { askConfirm } from '@/lib/confirm';
import { vSortable } from '@/lib/sortable';
import { postJson } from '@/lib/http';
import { toast } from '@/lib/toast';

const props = defineProps({ banks: Array, shared: { type: Array, default: () => [] }, bankId: String, questions: Array, tags: Array, filters: Object, types: Object, difficulties: Object, subjects: Array, sort: { type: String, default: 'manual' } });
const bank = computed(() => props.banks.find((b) => b.id === props.bankId) ?? props.shared.find((b) => b.id === props.bankId));
const readonly = computed(() => !!bank.value && !bank.value.mine);

// Cari bank (nama, mapel, kelas, atau nama guru) saat bank sudah banyak
const bankQ = ref('');
const matchBank = (b) => !bankQ.value.trim() || `${b.name} ${b.subject ?? ''} ${b.grade ? 'kelas ' + b.grade : ''} ${b.owner ?? ''}`.toLowerCase().includes(bankQ.value.trim().toLowerCase());
const myBanks = computed(() => props.banks.filter(matchBank));
const sharedBanks = computed(() => props.shared.filter(matchBank));
const copyBank = () => router.post(route('banks.copy', bank.value.id));

// ---------- Saring
const f = reactive({ q: props.filters.q ?? '', type: props.filters.type ?? '', difficulty: props.filters.difficulty ?? '', tag: props.filters.tag ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => go(props.bankId), 300);
});
const sortMode = ref(props.sort);
const sortLabels = { manual: 'Urutan saya (bisa digeser)', oldest: 'Dibuat paling lama', newest: 'Dibuat paling baru' };
watch(sortMode, () => go(props.bankId));
const go = (id, reset = false) => {
    const params = { bank: id, ...(reset ? {} : Object.fromEntries(Object.entries(f).filter(([, v]) => v))), ...(sortMode.value !== 'manual' ? { sort: sortMode.value } : {}) };
    router.get(route('banks.index'), params, { preserveState: true, preserveScroll: true, replace: true });
};
const openBank = (id) => {
    Object.assign(f, { q: '', type: '', difficulty: '', tag: '' });
    go(id, true);
};

// ---------- Bank
const bankOpen = ref(false);
const editingBank = ref(null);
const bf = useForm({ name: '', subject: '', grade: null, description: '', is_public: false });
const newBank = () => { editingBank.value = null; bf.defaults({ name: '', subject: '', grade: null, description: '', is_public: false }).reset(); bf.clearErrors(); bankOpen.value = true; };
const editBank = () => { editingBank.value = bank.value; bf.defaults({ name: bank.value.name, subject: bank.value.subject ?? '', grade: bank.value.grade, description: bank.value.description ?? '', is_public: !!bank.value.is_public }).reset(); bankOpen.value = true; };
const saveBank = () => {
    const opts = { preserveScroll: true, onSuccess: () => (bankOpen.value = false) };
    editingBank.value ? bf.put(route('banks.update', editingBank.value.id), opts) : bf.post(route('banks.store'), opts);
};
const deleteBank = async () => {
    if (await askConfirm({ title: `Hapus bank "${bank.value.name}"?`, message: `${bank.value.count} soal di dalamnya ikut terhapus.`, confirmText: 'Hapus bank', tone: 'danger' })) {
        router.delete(route('banks.destroy', bank.value.id));
    }
};

// ---------- Soal
const editorOpen = ref(false);
const editing = ref(null);
const importOpen = ref(false);
const viewing = ref(null);
const showKeys = ref(true);
const add = () => { editing.value = null; editorOpen.value = true; };
const edit = (q) => { editing.value = q; editorOpen.value = true; };
const duplicate = (q) => router.post(route('questions.duplicate', q.id), {}, { preserveScroll: true });
const remove = async (q) => {
    if (await askConfirm({ title: 'Hapus soal ini?', confirmText: 'Hapus', tone: 'danger' })) router.delete(route('questions.destroy', q.id), { preserveScroll: true });
};
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const diffTone = { 1: 'success', 2: 'warning', 3: 'danger' };
const typeIcon = { mc: '🔘', mcm: '☑️', tf: '⚖️', short: '✏️', match: '🔗' };
const totalPoints = computed(() => props.questions.reduce((s, q) => s + q.points, 0));
const filtered = computed(() => Object.values(f).some(Boolean));

// ---------- Urutan soal: geser (seret), ketik nomor, atau menurut waktu dibuat
const list = ref([...props.questions]);
watch(() => props.questions, (v) => (list.value = [...v]));
const canReorder = computed(() => !readonly.value && sortMode.value === 'manual' && !filtered.value && list.value.length > 1);

const applyOrder = async (ordered) => {
    const before = list.value;
    list.value = ordered;
    try {
        await postJson(route('questions.reorder', props.bankId), { ids: ordered.map((q) => q.id) });
    } catch (e) {
        list.value = before;
        toast(e.message, 'error');
    }
};
const moveTo = (id, index) => {
    const moving = list.value.find((q) => q.id === id);
    if (!moving) return;
    const rest = list.value.filter((q) => q.id !== id);
    rest.splice(Math.max(0, Math.min(index, rest.length)), 0, moving);
    if (rest.every((q, i) => q.id === list.value[i].id)) return;
    applyOrder(rest);
};
const onMove = ({ id, newIndex }) => moveTo(id, newIndex);

// Nomor yang bisa diketik: ketuk lingkaran nomor, isi nomor tujuan, Enter
const numbering = ref(null);
const numberValue = ref('');
const startNumber = (q, i) => { if (!canReorder.value) return; numbering.value = q.id; numberValue.value = String(i + 1); };
const commitNumber = (q) => {
    const n = parseInt(numberValue.value, 10);
    numbering.value = null;
    if (Number.isFinite(n)) moveTo(q.id, Math.min(Math.max(n, 1), list.value.length) - 1);
};
</script>

<template>
    <Head title="Bank soal" />
    <AppLayout title="Bank soal 📚" wide>
        <template #actions><Btn variant="soft" @click="newBank">+ Bank baru</Btn></template>

        <EmptyState v-if="!banks.length && !shared.length" title="Belum ada bank soal" text="Buat bank soal per mapel atau bab, lalu tulis soal atau impor dari templat Excel/Word.">
            <Btn @click="newBank">Buat bank soal pertama</Btn>
        </EmptyState>

        <div v-else class="grid gap-6 lg:grid-cols-[16rem_1fr]">
            <!-- Daftar bank -->
            <aside class="min-w-0 space-y-3">
                <input v-model="bankQ" type="search" placeholder="Cari bank soal…" aria-label="Cari bank soal"
                    class="h-11 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                <p v-if="!banks.length" class="rounded-xl bg-surface2 px-3 py-3 text-sm text-muted">Kamu belum punya bank sendiri. <button class="font-bold text-ink underline" @click="newBank">Buat bank</button> atau salin dari bank guru lain di bawah.</p>
                <div class="flex gap-2 overflow-x-auto pb-1 lg:max-h-[60dvh] lg:flex-col lg:overflow-x-visible lg:overflow-y-auto">
                    <button v-for="b in myBanks" :key="b.id" type="button" class="min-w-44 shrink-0 rounded-2xl border-2 p-3 text-left transition lg:min-w-0"
                        :class="b.id === bankId ? 'border-accent bg-surface2' : 'border-line bg-surface hover:border-accent/50'" @click="openBank(b.id)">
                        <p class="leading-tight font-bold">{{ b.name }}<span v-if="b.is_public" class="ml-1.5 rounded bg-accent/15 px-1.5 py-0.5 align-middle text-[10px] font-bold text-accent uppercase">Publik</span></p>
                        <p class="mt-1 text-xs text-muted">{{ [b.subject, b.grade ? 'Kelas ' + b.grade : null].filter(Boolean).join(' · ') || 'Tanpa label' }} · {{ b.count }} soal</p>
                    </button>
                    <p v-if="banks.length && !myBanks.length" class="px-1 text-sm text-muted">Tidak ada bank sendiri yang cocok.</p>
                </div>
                <div v-if="shared.length">
                    <p class="mb-2 text-xs font-bold text-muted uppercase">Dibagikan guru lain</p>
                    <div class="flex gap-2 overflow-x-auto pb-1 lg:max-h-[40dvh] lg:flex-col lg:overflow-x-visible lg:overflow-y-auto">
                        <button v-for="b in sharedBanks" :key="b.id" type="button" class="min-w-44 shrink-0 rounded-2xl border-2 border-dashed p-3 text-left transition lg:min-w-0"
                            :class="b.id === bankId ? 'border-accent bg-surface2' : 'border-line bg-surface hover:border-accent/50'" @click="openBank(b.id)">
                            <p class="leading-tight font-bold">{{ b.name }}</p>
                            <p class="mt-1 text-xs text-muted">{{ b.owner }} · {{ [b.subject, b.grade ? 'Kelas ' + b.grade : null].filter(Boolean).join(' · ') || 'Tanpa label' }} · {{ b.count }} soal</p>
                        </button>
                        <p v-if="!sharedBanks.length" class="px-1 text-sm text-muted">Tidak ada yang cocok.</p>
                    </div>
                </div>
            </aside>

            <section v-if="bank" class="min-w-0">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-2xl font-bold">{{ bank.name }}</h2>
                        <p class="text-sm text-muted">{{ [bank.subject, bank.grade ? 'Kelas ' + bank.grade : null].filter(Boolean).join(' · ') }}<span v-if="questions.length"> · {{ questions.length }} soal{{ filtered ? ' (tersaring)' : '' }} · {{ totalPoints }} poin</span></p>
                        <p v-if="bank.description" class="mt-1 text-sm">{{ bank.description }}</p>
                        <p v-if="readonly" class="mt-2 rounded-xl bg-surface2 px-3 py-2 text-sm font-semibold">👀 Bank milik {{ bank.owner }} yang dibagikan ke guru. Kamu bisa melihat dan memakai soalnya di kuis, atau menyalinnya untuk diubah sendiri.</p>
                        <p v-else-if="bank.is_public" class="mt-2 text-sm font-semibold text-accent">🌐 Dibagikan ke guru lain di sekolah ini (hanya bisa dibaca mereka).</p>
                    </div>
                    <div v-if="!readonly" class="flex flex-wrap gap-2">
                        <a :href="route('banks.print', bank.id)" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-4 font-display text-sm font-semibold hover:bg-line/60">🖨️ Cetak</a>
                        <Btn size="sm" variant="ghost" @click="editBank">✏️ Ubah</Btn>
                        <Btn size="sm" variant="ghost" @click="deleteBank">🗑️</Btn>
                        <Btn size="sm" variant="soft" @click="importOpen = true">📥 Impor Excel/Word</Btn>
                        <Btn size="sm" @click="add">+ Soal</Btn>
                    </div>
                    <div v-else class="flex flex-wrap gap-2">
                        <a :href="route('banks.print', bank.id)" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-4 font-display text-sm font-semibold hover:bg-line/60">🖨️ Cetak</a>
                        <Btn size="sm" @click="copyBank">📋 Salin ke bank saya</Btn>
                    </div>
                </div>

                <div class="mb-4 grid gap-2 sm:grid-cols-5">
                    <input v-model="f.q" type="search" placeholder="Cari teks soal" class="h-11 rounded-xl border-2 border-line bg-surface px-4 text-ink sm:col-span-2" />
                    <select v-model="f.type" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Tipe"><option value="">Semua tipe</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                    <select v-model="f.difficulty" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Kesulitan"><option value="">Semua tingkat</option><option v-for="(l, k) in difficulties" :key="k" :value="k">{{ l }}</option></select>
                    <select v-model="f.tag" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Topik"><option value="">Semua topik</option><option v-for="t in tags" :key="t" :value="t">{{ t }}</option></select>
                </div>
                <div class="mb-4 flex flex-wrap items-center gap-x-5 gap-y-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-muted"><input v-model="showKeys" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Tampilkan kunci</label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-muted">Urutan
                        <select v-model="sortMode" class="h-10 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Urutan soal">
                            <option v-for="(l, k) in sortLabels" :key="k" :value="k">{{ l }}</option>
                        </select>
                    </label>
                    <span v-if="canReorder" class="text-xs text-muted">↕ Geser ⠿ atau ketuk nomor untuk memindah.</span>
                    <span v-else-if="sortMode === 'manual' && filtered" class="text-xs text-muted">Hapus saringan untuk mengubah urutan.</span>
                </div>

                <EmptyState v-if="!questions.length" :title="filtered ? 'Tidak ada soal yang cocok' : 'Bank ini masih kosong'" :text="filtered ? 'Ubah saringan pencarian.' : 'Tulis soal pertama atau impor sekaligus dari Excel/Word.'">
                    <div v-if="!filtered && !readonly" class="flex flex-wrap justify-center gap-2"><Btn @click="add">+ Soal</Btn><Btn variant="soft" @click="importOpen = true">📥 Impor</Btn></div>
                </EmptyState>

                <ol v-else v-sortable="{ disabled: !canReorder, handle: '.q-handle', onMove }" class="space-y-3">
                    <li v-for="(q, i) in list" :key="q.id" :data-id="q.id" class="rounded-card border border-line bg-surface p-4 shadow-card">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5 text-xs">
                            <span v-if="canReorder" class="q-handle cursor-grab touch-none px-1 text-lg leading-none text-muted select-none active:cursor-grabbing" title="Tahan lalu geser untuk memindah" aria-hidden="true">⠿</span>
                            <input v-if="numbering === q.id" v-model="numberValue" type="number" min="1" :max="list.length" autofocus
                                class="size-7 rounded-full border-2 border-accent bg-surface text-center font-bold text-ink [appearance:textfield]" aria-label="Pindah ke nomor"
                                @keydown.enter.prevent="commitNumber(q)" @keydown.esc="numbering = null" @blur="commitNumber(q)" />
                            <button v-else type="button" class="grid size-7 place-items-center rounded-full bg-ink font-bold text-bg" :class="canReorder ? 'hover:ring-2 hover:ring-accent' : 'cursor-default'"
                                :title="canReorder ? 'Ketuk untuk pindah ke nomor lain' : ''" @click="startNumber(q, i)">{{ i + 1 }}</button>
                            <Badge tone="neutral">{{ typeIcon[q.type] }} {{ q.type_label }}</Badge>
                            <Badge :tone="diffTone[q.difficulty]">{{ difficulties[q.difficulty] }}</Badge>
                            <Badge tone="accent">{{ q.points }} poin</Badge>
                            <span v-for="t in q.tags" :key="t" class="rounded bg-surface2 px-1.5 py-0.5 font-semibold text-muted">#{{ t }}</span>
                            <span v-if="!readonly" class="ml-auto flex gap-1">
                                <button class="rounded-lg px-2 py-1 font-bold hover:bg-surface2" @click="edit(q)">Ubah</button>
                                <button class="rounded-lg px-2 py-1 font-bold hover:bg-surface2" @click="duplicate(q)">Duplikat</button>
                                <button class="rounded-lg px-2 py-1 font-bold text-danger hover:bg-surface2" @click="remove(q)">Hapus</button>
                            </span>
                        </div>
                        <MathHtml :html="q.prompt" class="rich" />
                        <div v-if="q.media.length" class="mt-2 flex flex-wrap gap-2">
                            <button v-for="m in q.media" :key="m.id" type="button" class="overflow-hidden rounded-xl" @click="viewing = m">
                                <img v-if="m.kind === 'image'" :src="m.thumb_url || m.url" :alt="m.name" class="h-28 w-auto max-w-56 object-cover" />
                                <span v-else class="flex h-12 items-center gap-2 bg-surface2 px-3 text-sm font-bold">{{ m.kind === 'audio' ? '🎵' : '🎬' }} {{ m.name }}</span>
                            </button>
                        </div>
                        <ul v-if="q.options.length" class="mt-3 grid gap-1.5 sm:grid-cols-2">
                            <li v-for="(o, j) in q.options" :key="o.id" class="flex items-start gap-2 rounded-xl px-3 py-2"
                                :class="showKeys && o.correct ? 'bg-success/15 font-bold text-success' : 'bg-surface2/60'">
                                <span class="font-display font-bold">{{ q.type === 'tf' ? (o.correct && showKeys ? '✓' : '•') : LETTERS[j] + '.' }}</span>
                                <MathHtml :html="o.text" plain tag="span" />
                            </li>
                        </ul>
                        <div v-if="q.type === 'match'" class="mt-3 space-y-1.5">
                            <p v-for="(pr, j) in q.pairs" :key="j" class="flex items-center gap-2 rounded-xl bg-surface2/60 px-3 py-2 text-sm">
                                <span class="font-bold">{{ j + 1 }}. {{ pr.left }}</span><span class="text-muted">⟷</span>
                                <span :class="showKeys ? 'font-bold text-success' : 'text-muted'">{{ showKeys ? pr.right : '……' }}</span>
                            </p>
                            <p v-if="q.extras.length" class="text-xs text-muted">Pengecoh: {{ q.extras.join(' · ') }}</p>
                        </div>
                        <p v-if="q.answers.length && showKeys" class="mt-3 rounded-xl bg-success/15 px-3 py-2 text-sm font-bold text-success">Kunci: {{ q.answers.join(' / ') }}</p>
                        <details v-if="q.explanation" class="mt-2 text-sm"><summary class="cursor-pointer font-bold text-muted">Pembahasan</summary><MathHtml :html="q.explanation" class="rich mt-1" /></details>
                    </li>
                </ol>
            </section>
        </div>

        <QuestionEditor :show="editorOpen" :bank-id="bankId" :question="editing" :types="types" :difficulties="difficulties" @close="editorOpen = false" />
        <ImportDialog :show="importOpen" :bank="bank" @close="importOpen = false" />
        <MediaViewer :item="viewing" @close="viewing = null" />

        <Modal :show="bankOpen" :title="editingBank ? 'Ubah bank soal' : 'Bank soal baru'" @close="bankOpen = false">
            <form class="space-y-4" @submit.prevent="saveBank">
                <Field v-model="bf.name" label="Nama" placeholder="mis. IPA 7 — Bab 2 Sel" maxlength="120" :error="bf.errors.name" />
                <div class="grid grid-cols-2 gap-3">
                    <Field v-model="bf.subject" label="Mapel" list="subject-list" maxlength="60" placeholder="IPA" :error="bf.errors.subject" />
                    <Field v-model.number="bf.grade" as="select" label="Kelas" :error="bf.errors.grade">
                        <option :value="null">–</option>
                        <option v-for="g in [7, 8, 9, 1, 2, 3, 4, 5, 6, 10, 11, 12]" :key="g" :value="g">{{ g }}</option>
                    </Field>
                </div>
                <datalist id="subject-list"><option v-for="s in subjects" :key="s" :value="s" /></datalist>
                <Field v-model="bf.description" as="textarea" :rows="2" label="Catatan (opsional)" maxlength="500" />
                <label class="flex items-start gap-3 rounded-xl bg-surface2/70 p-3">
                    <input v-model="bf.is_public" type="checkbox" class="mt-0.5 size-5 shrink-0 accent-[var(--m-accent)]" />
                    <span class="text-sm"><b>Bagikan ke guru lain</b> (publik)<br /><span class="text-muted">Guru lain di sekolah ini bisa melihat dan memakai soal di bank ini, tetapi tidak bisa mengubahnya. Bawaan: pribadi, hanya kamu.</span></span>
                </label>
                <Btn type="submit" block :loading="bf.processing">{{ editingBank ? 'Simpan' : 'Buat bank soal' }}</Btn>
            </form>
        </Modal>
    </AppLayout>
</template>
