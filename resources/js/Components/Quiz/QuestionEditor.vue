<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import RichText from '@/Components/Editor/RichText.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import { upload } from '@/lib/http';
import { askConfirm } from '@/lib/confirm';
import { toast } from '@/lib/toast';

// Editor soal (QZ-03, QZ-04, QZ-06). Kunci dinilai ulang di server.
const props = defineProps({ show: Boolean, bankId: String, question: Object, types: Object, difficulties: Object });
const emit = defineEmits(['close']);
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

const blank = () => ({
    type: 'mc', prompt: '', explanation: '', difficulty: 2, points: 1, tags: [], media: [],
    spec: { options: ['', '', '', ''], correct: [], answers: [''], pairs: [{ left: '', right: '' }, { left: '', right: '' }, { left: '', right: '' }], extras: [] },
});
const form = useForm(blank());
const media = ref([]);
const tagInput = ref('');
const showExplanation = ref(false);

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    const q = props.question;
    if (!q) {
        form.defaults(blank()).reset();
        media.value = [];
        showExplanation.value = false;
        return;
    }
    const e = q.editor;
    form.defaults({
        type: q.type, prompt: q.prompt, explanation: q.explanation ?? '', difficulty: q.difficulty, points: q.points, tags: [...q.tags],
        media: q.media.map((m) => m.id),
        spec: {
            options: e.options ? [...e.options] : ['', '', '', ''],
            correct: q.type === 'tf' ? e.correct : (e.correct ?? []),
            answers: e.answers ? [...e.answers] : [''],
            pairs: e.pairs ? e.pairs.map((p) => ({ ...p })) : [{ left: '', right: '' }, { left: '', right: '' }, { left: '', right: '' }],
            extras: e.extras ? [...e.extras] : [],
        },
    }).reset();
    media.value = [...q.media];
    showExplanation.value = !!q.explanation;
});

// Ganti tipe: sesuaikan bentuk kunci
watch(() => form.type, (t, old) => {
    if (t === old) return;
    if (t === 'tf') form.spec.correct = typeof form.spec.correct === 'boolean' ? form.spec.correct : true;
    else if (t === 'mc') form.spec.correct = Array.isArray(form.spec.correct) ? form.spec.correct.slice(0, 1) : [];
    else if (t === 'mcm') form.spec.correct = Array.isArray(form.spec.correct) ? form.spec.correct : [];
});

const isChoice = computed(() => form.type === 'mc' || form.type === 'mcm');
const isCorrect = (i) => Array.isArray(form.spec.correct) && form.spec.correct.includes(i);
const toggleCorrect = (i) => {
    if (form.type === 'mc') form.spec.correct = [i];
    else form.spec.correct = isCorrect(i) ? form.spec.correct.filter((x) => x !== i) : [...form.spec.correct, i];
};
const addOption = () => form.spec.options.length < 6 && form.spec.options.push('');
const removeOption = (i) => {
    form.spec.options.splice(i, 1);
    form.spec.correct = (form.spec.correct || []).filter((x) => x !== i).map((x) => (x > i ? x - 1 : x));
};
const addPair = () => form.spec.pairs.length < 8 && form.spec.pairs.push({ left: '', right: '' });
const addExtra = () => form.spec.extras.length < 4 && form.spec.extras.push('');
const addAnswer = () => form.spec.answers.length < 10 && form.spec.answers.push('');

const addTag = () => {
    const t = tagInput.value.replace(/,/g, '').trim();
    if (t && !form.tags.includes(t) && form.tags.length < 5) form.tags.push(t);
    tagInput.value = '';
};

// Media
const uploading = ref(0);
const pick = async (e) => {
    for (const file of [...e.target.files].slice(0, 3 - media.value.length)) {
        uploading.value++;
        try {
            const card = await upload(route('questions.upload'), file);
            media.value.push(card);
            form.media.push(card.id);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            uploading.value--;
        }
    }
    e.target.value = '';
};
const removeMedia = (m) => {
    media.value = media.value.filter((x) => x.id !== m.id);
    form.media = form.media.filter((id) => id !== m.id);
};

// Tutup lewat ✕ atau Esc: bila ada isian yang belum disimpan, tanyakan dulu
const tryClose = async () => {
    const dirty = form.isDirty || tagInput.value.trim() !== '' || uploading.value > 0;
    if (!dirty || await askConfirm({ title: 'Tutup tanpa menyimpan?', message: 'Isian soal ini belum disimpan dan akan hilang.', confirmText: 'Tutup, buang isian', cancelText: 'Lanjut menulis', tone: 'danger' })) emit('close');
};

const hasMath = computed(() => (form.prompt || '').includes('$'));

const submit = () => {
    if (uploading.value) return toast('Tunggu unggahan selesai.', 'error');
    if (tagInput.value.trim()) addTag();
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    const payload = (d) => ({ ...d, spec: d.type === 'tf' ? { correct: d.spec.correct } : d.type === 'short' ? { answers: d.spec.answers } : d.type === 'match' ? { pairs: d.spec.pairs, extras: d.spec.extras } : { options: d.spec.options, correct: d.spec.correct } });
    form.transform(payload);
    if (props.question) form.put(route('questions.update', props.question.id), opts);
    else form.post(route('questions.store', props.bankId), opts);
};
const typeIcons = { mc: '🔘', mcm: '☑️', tf: '⚖️', short: '✏️', match: '🔗' };
</script>

<template>
    <Modal :show="show" :title="question ? 'Ubah soal' : 'Soal baru'" wide persistent @close="tryClose">
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5" role="radiogroup" aria-label="Tipe soal">
                <button v-for="(label, key) in types" :key="key" type="button" role="radio" :aria-checked="form.type === key"
                    class="rounded-xl border-2 px-2 py-2.5 text-sm font-bold transition" :class="form.type === key ? 'border-accent bg-surface2' : 'border-line'"
                    @click="form.type = key"><span class="block text-xl">{{ typeIcons[key] }}</span>{{ label }}</button>
            </div>

            <div>
                <RichText v-model="form.prompt" label="Pertanyaan" placeholder="Tulis soal… Rumus: $x^2 + \frac{1}{2}$" :error="form.errors.prompt" />
                <div v-if="hasMath" class="mt-2 rounded-xl bg-surface2/70 px-4 py-3">
                    <p class="mb-1 text-xs font-bold text-muted uppercase">Pratinjau rumus</p>
                    <MathHtml :html="form.prompt" class="rich" />
                </div>
            </div>

            <div>
                <p class="mb-1.5 text-sm font-bold">Gambar / audio / video <span class="font-normal text-muted">(maks. 3)</span></p>
                <div class="flex flex-wrap items-center gap-2">
                    <div v-for="m in media" :key="m.id" class="relative">
                        <img v-if="m.kind === 'image'" :src="m.thumb_url || m.url" :alt="m.name" class="size-20 rounded-xl object-cover" />
                        <div v-else class="grid size-20 place-items-center rounded-xl bg-surface2 p-1 text-center text-xs">{{ m.kind === 'audio' ? '🎵' : '🎬' }}<br />{{ m.name.slice(0, 14) }}</div>
                        <button type="button" class="absolute -top-2 -right-2 grid size-6 place-items-center rounded-full bg-danger text-xs font-bold text-bg" :aria-label="`Hapus ${m.name}`" @click="removeMedia(m)">✕</button>
                    </div>
                    <label v-if="media.length < 3" class="grid size-20 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-line text-center text-xs font-bold text-muted hover:text-ink">
                        <span>{{ uploading ? 'Mengunggah…' : '+ Media' }}</span>
                        <input type="file" class="sr-only" accept="image/*,audio/*,video/*" multiple @change="pick" />
                    </label>
                </div>
            </div>

            <!-- Kunci -->
            <div v-if="isChoice" class="space-y-2">
                <p class="text-sm font-bold">Pilihan jawaban <span class="font-normal text-muted">— ketuk huruf untuk menandai {{ form.type === 'mc' ? 'jawaban benar' : 'semua jawaban benar' }}</span></p>
                <div v-for="(o, i) in form.spec.options" :key="i" class="flex items-center gap-2">
                    <button type="button" class="grid size-11 shrink-0 place-items-center rounded-xl border-2 font-display font-bold transition"
                        :class="isCorrect(i) ? 'border-success bg-success text-bg' : 'border-line'" :aria-pressed="isCorrect(i)" :aria-label="`Tandai ${LETTERS[i]} benar`"
                        @click="toggleCorrect(i)">{{ isCorrect(i) ? '✓' : LETTERS[i] }}</button>
                    <input v-model="form.spec.options[i]" maxlength="1000" :placeholder="`Pilihan ${LETTERS[i]}`"
                        class="h-11 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                    <button v-if="form.spec.options.length > 2" type="button" class="grid size-9 place-items-center rounded-full text-muted hover:text-danger" :aria-label="`Hapus pilihan ${LETTERS[i]}`" @click="removeOption(i)">✕</button>
                </div>
                <Btn v-if="form.spec.options.length < 6" size="sm" variant="ghost" @click="addOption">+ Pilihan</Btn>
            </div>

            <div v-else-if="form.type === 'tf'" class="grid grid-cols-2 gap-2">
                <button v-for="v in [true, false]" :key="String(v)" type="button" class="h-14 rounded-xl border-2 text-lg font-bold"
                    :class="form.spec.correct === v ? 'border-success bg-success text-bg' : 'border-line'" @click="form.spec.correct = v">{{ v ? '✓ Benar' : '✗ Salah' }}</button>
            </div>

            <div v-else-if="form.type === 'match'" class="space-y-3">
                <p class="text-sm font-bold">Pasangan <span class="font-normal text-muted">— kiri: istilah/pertanyaan, kanan: jodohnya. Di layar siswa, kartu kanan diacak.</span></p>
                <div v-for="(p, i) in form.spec.pairs" :key="i" class="flex items-center gap-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-sm font-bold text-bg">{{ i + 1 }}</span>
                    <input v-model="p.left" maxlength="200" :placeholder="`Kiri ${i + 1}`" class="h-11 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                    <span class="text-muted" aria-hidden="true">⟷</span>
                    <input v-model="p.right" maxlength="200" :placeholder="`Kanan ${i + 1}`" class="h-11 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                    <button v-if="form.spec.pairs.length > 2" type="button" class="grid size-9 shrink-0 place-items-center rounded-full text-muted hover:text-danger" :aria-label="`Hapus pasangan ${i + 1}`" @click="form.spec.pairs.splice(i, 1)">✕</button>
                </div>
                <Btn v-if="form.spec.pairs.length < 8" size="sm" variant="ghost" @click="addPair">+ Pasangan</Btn>

                <div class="rounded-xl bg-surface2/60 p-3">
                    <p class="mb-2 text-sm font-bold">Pengecoh <span class="font-normal text-muted">(opsional, kartu kanan tambahan yang tidak punya pasangan)</span></p>
                    <div v-for="(x, i) in form.spec.extras" :key="i" class="mb-2 flex gap-2">
                        <input v-model="form.spec.extras[i]" maxlength="200" placeholder="Kartu pengecoh" class="h-11 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                        <button type="button" class="px-2 text-muted hover:text-danger" aria-label="Hapus pengecoh" @click="form.spec.extras.splice(i, 1)">✕</button>
                    </div>
                    <Btn v-if="form.spec.extras.length < 4" size="sm" variant="ghost" @click="addExtra">+ Pengecoh</Btn>
                </div>
                <p class="text-xs text-muted">Nilai sebagian: tiap pasangan yang benar mendapat porsi poin yang sama.</p>
            </div>

            <div v-else class="space-y-2">
                <p class="text-sm font-bold">Kunci jawaban <span class="font-normal text-muted">— huruf besar/kecil & spasi diabaikan; boleh lebih dari satu</span></p>
                <div v-for="(a, i) in form.spec.answers" :key="i" class="flex gap-2">
                    <input v-model="form.spec.answers[i]" maxlength="200" placeholder="Jawaban benar"
                        class="h-11 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                    <button v-if="form.spec.answers.length > 1" type="button" class="px-2 text-muted hover:text-danger" aria-label="Hapus kunci" @click="form.spec.answers.splice(i, 1)">✕</button>
                </div>
                <Btn v-if="form.spec.answers.length < 10" size="sm" variant="ghost" @click="addAnswer">+ Kunci lain</Btn>
            </div>
            <p v-if="form.errors.spec" class="text-sm font-semibold text-danger">{{ form.errors.spec }}</p>

            <div v-if="showExplanation"><RichText v-model="form.explanation" label="Pembahasan (tampil setelah siswa selesai)" :error="form.errors.explanation" /></div>
            <Btn v-else size="sm" variant="ghost" @click="showExplanation = true">+ Pembahasan</Btn>

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block">
                    <span class="mb-1.5 block text-sm font-bold">Kesulitan</span>
                    <select v-model.number="form.difficulty" class="h-11 w-full rounded-xl border-2 border-line bg-surface px-3 text-ink">
                        <option v-for="(label, k) in difficulties" :key="k" :value="Number(k)">{{ label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-bold">Poin</span>
                    <input v-model.number="form.points" type="number" min="1" max="100" class="h-11 w-full rounded-xl border-2 border-line bg-surface px-3 text-ink" />
                </label>
                <div>
                    <span class="mb-1.5 block text-sm font-bold">Topik</span>
                    <div class="flex min-h-11 flex-wrap items-center gap-1 rounded-xl border-2 border-line bg-surface px-2 py-1">
                        <span v-for="t in form.tags" :key="t" class="inline-flex items-center gap-1 rounded-lg bg-surface2 px-2 py-0.5 text-sm font-semibold">{{ t }}
                            <button type="button" class="text-muted hover:text-danger" :aria-label="`Hapus topik ${t}`" @click="form.tags = form.tags.filter((x) => x !== t)">✕</button></span>
                        <input v-if="form.tags.length < 5" v-model="tagInput" placeholder="ketik lalu Enter" maxlength="40" class="h-8 min-w-24 flex-1 bg-transparent text-sm text-ink focus:outline-none"
                            @keydown.enter.prevent="addTag" @keydown.,.prevent="addTag" @blur="addTag" />
                    </div>
                </div>
            </div>

            <Btn type="submit" block size="lg" :loading="form.processing">{{ question ? 'Simpan perubahan' : 'Simpan soal' }}</Btn>
        </form>
    </Modal>
</template>
