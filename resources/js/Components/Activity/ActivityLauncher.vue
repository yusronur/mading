<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import Btn from '@/Components/Btn.vue';
import { startActivity } from '@/lib/activity';
import { toast } from '@/lib/toast';

// Peluncur aktivitas live untuk pengelola papan (LIV-01..04).
const props = defineProps({ show: Boolean, boardId: String, hasClass: Boolean });
const emit = defineEmits(['close']);

const TYPES = [
    { key: 'timer', icon: '⏱️', name: 'Timer', hint: 'Hitung mundur di semua layar' },
    { key: 'poll', icon: '📊', name: 'Poll', hint: 'Pilih satu, grafik bergerak langsung' },
    { key: 'cloud', icon: '☁️', name: 'Word cloud', hint: 'Siswa mengetik kata, tampil sebagai awan' },
    { key: 'wheel', icon: '🎡', name: 'Spin wheel', hint: 'Putar untuk memilih nama' },
];
const type = ref('timer');
const busy = ref(false);
const error = ref('');

const f = reactive({
    title: '', seconds: 180,
    question: '', options: ['', '', '', ''], results: 'live',
    prompt: '', max_words: 3,
    source: 'list', names: '', no_repeat: true,
});
const PRESETS = [60, 180, 300, 600, 900];
const mins = (s) => (s % 60 === 0 ? `${s / 60} menit` : `${s} dtk`);

watch(() => props.show, (v) => { if (v) { error.value = ''; f.source = props.hasClass ? 'class' : 'list'; } });

const addOption = () => { if (f.options.length < 6) f.options.push(''); };
const nameCount = computed(() => f.names.split(/\n+/).map((x) => x.trim()).filter(Boolean).length);

const submit = async () => {
    error.value = '';
    busy.value = true;
    const payload = { type: type.value, title: f.title || null };
    if (type.value === 'timer') payload.seconds = Number(f.seconds);
    if (type.value === 'poll') Object.assign(payload, { question: f.question, options: f.options, results: f.results });
    if (type.value === 'cloud') Object.assign(payload, { prompt: f.prompt, max_words: Number(f.max_words) });
    if (type.value === 'wheel') Object.assign(payload, { source: f.source, names: f.names, no_repeat: f.no_repeat });
    try {
        await startActivity(props.boardId, payload);
        toast('Aktivitas dimulai di semua layar.', 'success');
        emit('close');
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Modal :show="show" title="Aktivitas live" persistent @close="emit('close')">
        <div class="grid grid-cols-2 gap-2">
            <button v-for="t in TYPES" :key="t.key" type="button" class="rounded-xl border-2 p-3 text-left transition" :class="type === t.key ? 'border-accent bg-accent/10' : 'border-line hover:bg-surface2'" :aria-pressed="type === t.key" :data-testid="'act-' + t.key" @click="type = t.key">
                <span class="text-2xl">{{ t.icon }}</span>
                <span class="block font-bold">{{ t.name }}</span>
                <span class="block text-xs text-muted">{{ t.hint }}</span>
            </button>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="submit">
            <div v-if="type === 'timer'" class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <button v-for="s in PRESETS" :key="s" type="button" class="rounded-xl border-2 px-3 py-2 font-bold" :class="Number(f.seconds) === s ? 'border-accent bg-accent/10' : 'border-line'" @click="f.seconds = s">{{ mins(s) }}</button>
                </div>
                <Field v-model="f.seconds" label="Atau tulis detik" type="number" min="5" max="7200" />
                <Field v-model="f.title" label="Judul (opsional)" maxlength="200" placeholder="Mis. Waktu diskusi kelompok" />
            </div>

            <div v-else-if="type === 'poll'" class="space-y-3">
                <Field v-model="f.question" label="Pertanyaan" maxlength="200" placeholder="Mis. Hewan apa yang paling lucu?" />
                <div class="space-y-2">
                    <p class="text-sm font-bold">Pilihan (minimal 2)</p>
                    <input v-for="(o, i) in f.options" :key="i" v-model="f.options[i]" maxlength="80" :placeholder="`Pilihan ${i + 1}`" :aria-label="`Pilihan ${i + 1}`"
                        class="h-11 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                    <button v-if="f.options.length < 6" type="button" class="text-sm font-bold text-accent" @click="addOption">+ Tambah pilihan</button>
                </div>
                <label class="flex items-center gap-3 font-semibold"><input v-model="f.results" type="checkbox" true-value="closed" false-value="live" class="size-5 accent-[var(--m-accent)]" /> Sembunyikan hasil sampai poll ditutup</label>
            </div>

            <div v-else-if="type === 'cloud'" class="space-y-3">
                <Field v-model="f.prompt" label="Pertanyaan" maxlength="200" placeholder="Mis. Satu kata tentang energi" />
                <Field v-model="f.max_words" label="Maksimal kata per siswa" type="number" min="1" max="5" />
                <p class="text-sm text-muted">Kata kasar otomatis ditolak. Kamu bisa menghapus kata dari awan dengan mengkliknya.</p>
            </div>

            <div v-else class="space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="rounded-xl border-2 p-3 text-left font-bold disabled:opacity-50" :class="f.source === 'class' ? 'border-accent bg-accent/10' : 'border-line'" :disabled="!hasClass" @click="f.source = 'class'">
                        Siswa rombel papan<span class="block text-xs font-normal text-muted">{{ hasClass ? 'Nama diambil otomatis' : 'Papan ini tidak punya rombel' }}</span>
                    </button>
                    <button type="button" class="rounded-xl border-2 p-3 text-left font-bold" :class="f.source === 'list' ? 'border-accent bg-accent/10' : 'border-line'" @click="f.source = 'list'">
                        Ketik daftar nama<span class="block text-xs font-normal text-muted">Satu nama per baris</span>
                    </button>
                </div>
                <Field v-if="f.source === 'list'" v-model="f.names" as="textarea" :rows="6" label="Daftar nama" :hint="`${nameCount} nama (2 sampai 100)`" placeholder="Ani&#10;Budi&#10;Citra" />
                <label class="flex items-center gap-3 font-semibold"><input v-model="f.no_repeat" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Satu nama tidak terpilih dua kali</label>
            </div>

            <p v-if="error" class="rounded-xl bg-danger/15 px-4 py-2 font-semibold text-danger" role="alert">{{ error }}</p>
            <p class="text-sm text-muted">Aktivitas yang sedang berjalan akan digantikan.</p>
            <Btn type="submit" size="lg" block :loading="busy" data-testid="act-start">Mulai aktivitas</Btn>
        </form>
    </Modal>
</template>
