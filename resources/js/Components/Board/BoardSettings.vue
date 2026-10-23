<script setup>
import { watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import DateField from '@/Components/DateField.vue';
import { askConfirm } from '@/lib/confirm';
import { BACKGROUNDS, ICONS } from '@/lib/backgrounds';

const props = defineProps({ show: Boolean, board: Object, settings: Object, options: Object, can: Object });
const emit = defineEmits(['close']);

const clone = () => JSON.parse(JSON.stringify(props.settings ?? {}));
const sf = useForm(clone());
watch(() => props.show, (open) => {
    if (open) {
        sf.defaults(clone()).reset();
        sf.clearErrors();
    }
});

const addCriterion = () => {
    sf.rubric = [...(sf.rubric ?? []), { name: '', max: 10 }];
};
const removeCriterion = (i) => (sf.rubric = sf.rubric.filter((_, j) => j !== i));
const rubricMax = () => (sf.rubric ?? []).reduce((n, c) => n + (Number(c.max) || 0), 0);

const save = () => sf.transform((d) => ({ ...d, opens_at: d.opens_at || null, closes_at: d.closes_at || null })).put(route('boards.update', props.board.id), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
});
const newCode = async () => {
    const ok = await askConfirm({
        title: 'Ganti kode papan?',
        message: 'Kode lama tidak berlaku lagi. Siswa yang belum bergabung perlu kode baru; yang sudah bergabung tetap bisa membuka papan dari beranda.',
        confirmText: 'Ganti kode',
        tone: 'danger',
    });
    if (ok) router.post(route('boards.regenerate-code', props.board.id), {}, { preserveScroll: true });
};
const archive = () => router.post(route('boards.archive', props.board.id), { archive: !props.board.archived }, { preserveScroll: true, onSuccess: () => emit('close') });
const removeBoard = async () => {
    const ok = await askConfirm({
        title: `Hapus papan "${props.board.title}"?`,
        message: 'Papan dan semua kartunya tidak tampil lagi bagi siswa.',
        confirmText: 'Hapus papan',
        tone: 'danger',
    });
    if (ok) router.delete(route('boards.destroy', props.board.id));
};
</script>

<template>
    <Modal :show="show" title="Pengaturan papan" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="save">
            <Field v-model="sf.title" label="Judul" maxlength="150" :error="sf.errors.title" />
            <Field v-model="sf.description" as="textarea" :rows="3" label="Petunjuk untuk siswa" maxlength="1000"
                hint="Tampil di bawah judul papan." :error="sf.errors.description" />

            <fieldset class="space-y-3 rounded-2xl border border-line p-4">
                <legend class="px-2 text-sm font-bold text-muted uppercase">Tampilan</legend>
                <div>
                    <p class="mb-1.5 text-sm font-bold">Ikon</p>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" class="grid size-9 place-items-center rounded-lg border-2 text-xs" :class="!sf.icon ? 'border-accent' : 'border-transparent bg-surface2'" @click="sf.icon = null">–</button>
                        <button v-for="i in ICONS" :key="i" type="button" class="grid size-9 place-items-center rounded-lg border-2 text-lg" :class="sf.icon === i ? 'border-accent' : 'border-transparent bg-surface2'" @click="sf.icon = i">{{ i }}</button>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-sm font-bold">Latar</p>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                        <button v-for="(bg, key) in BACKGROUNDS" :key="key" type="button" class="h-14 rounded-xl border-2 bg-bg text-xs font-bold" :class="sf.background === key ? 'border-accent' : 'border-line'"
                            :style="bg.css ? { background: bg.css + ', var(--m-bg)' } : {}" @click="sf.background = key">{{ bg.label }}</button>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Field v-model="sf.format" as="select" label="Format papan">
                        <option v-for="(label, key) in options.formats" :key="key" :value="key">{{ label }}</option>
                    </Field>
                    <Field v-model="sf.sort" as="select" label="Urutan kartu">
                        <option v-for="(label, key) in options.sorts" :key="key" :value="key">{{ label }}</option>
                    </Field>
                </div>
                <Field v-model="sf.theme_id" as="select" label="Tema papan">
                    <option :value="null">Ikuti pilihan masing-masing siswa</option>
                    <option v-for="t in options.themes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </Field>
                <label v-if="sf.theme_id" class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.theme_locked" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Kunci tema ini untuk semua siswa
                </label>
            </fieldset>

            <fieldset class="space-y-3 rounded-2xl border border-line p-4">
                <legend class="px-2 text-sm font-bold text-muted uppercase">Akses</legend>
                <Field v-model="sf.visibility" as="select" label="Siapa yang bisa melihat?" :error="sf.errors.visibility">
                    <option v-for="(label, key) in options.visibilities" :key="key" :value="key">{{ label }}</option>
                </Field>
                <Field v-if="sf.visibility === 'class'" v-model="sf.class_id" as="select" label="Rombel" :error="sf.errors.class_id">
                    <option :value="null" disabled>Pilih rombel</option>
                    <option v-for="c in options.classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                </Field>
                <Field v-model="sf.visitor_permission" as="select" label="Hak siswa">
                    <option value="write">Boleh menempel kartu</option>
                    <option value="read">Hanya melihat</option>
                    <option value="moderate">Menempel + ikut memoderasi</option>
                </Field>
                <div v-if="sf.visibility === 'link' && settings.secret_url" class="rounded-xl bg-surface2 p-3 text-sm">
                    <p class="font-bold">🔗 Tautan rahasia</p>
                    <p class="break-all text-muted">{{ settings.secret_url }}</p>
                    <p class="mt-1 text-xs text-muted">Siswa tetap harus masuk akun sekolah. Kode papan juga bisa dipakai untuk bergabung.</p>
                </div>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.require_approval" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> 🛡️ Kartu dan komentar siswa perlu persetujuan guru
                </label>
                <Field v-model="sf.max_posts" type="number" min="0" max="100" label="Batas kartu per siswa" hint="0 = tanpa batas" />
                <Field v-model="sf.reaction" as="select" label="Reaksi pada kartu">
                    <option v-for="(label, key) in options.reactions" :key="key" :value="key">{{ label }}</option>
                </Field>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.allow_comments" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> 💬 Izinkan komentar
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.allow_anonymous" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Izinkan kartu anonim (guru tetap melihat penulisnya)
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.checklist_all" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> ☑️ Semua anggota boleh mencentang daftar di kartu (untuk tugas kelompok)
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="sf.is_frozen" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> ❄️ Bekukan papan (siswa tidak bisa menambah atau menghapus kartu)
                </label>
            </fieldset>

            <fieldset class="space-y-3 rounded-2xl border border-line p-4">
                <legend class="px-1 font-bold">⏰ Jadwal buka-tutup</legend>
                <p class="text-xs text-muted">Sebelum waktu buka, siswa belum melihat kartu. Setelah waktu tutup, papan hanya bisa dibaca. Kosongkan bila tidak perlu.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <DateField v-model="sf.opens_at" mode="datetime" label="Dibuka" placeholder="Langsung terbuka" :quick="['06:00', '07:00', '08:00', '12:00']" :error="sf.errors.opens_at" />
                    <DateField v-model="sf.closes_at" mode="datetime" label="Ditutup" placeholder="Tidak ditutup" :error="sf.errors.closes_at" />
                </div>
                <div class="flex gap-3 text-xs font-bold">
                    <button v-if="sf.opens_at" type="button" class="text-muted hover:text-danger" @click="sf.opens_at = ''">✕ hapus waktu buka</button>
                    <button v-if="sf.closes_at" type="button" class="text-muted hover:text-danger" @click="sf.closes_at = ''">✕ hapus waktu tutup</button>
                </div>
            </fieldset>

            <fieldset class="space-y-3 rounded-2xl border border-line p-4" data-testid="rubric-editor">
                <legend class="px-1 font-bold">📊 Rubrik penilaian kartu</legend>
                <p class="text-xs text-muted">Bila diisi, guru bisa menilai tiap kartu siswa per kriteria. Nilai akhir otomatis 0–100 dan masuk Rekap Nilai.</p>
                <div v-for="(c, i) in sf.rubric ?? []" :key="i" class="flex items-center gap-2">
                    <input v-model="c.name" maxlength="60" :placeholder="`Kriteria ${i + 1}, mis. Isi`" class="h-10 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-3" :aria-label="`Nama kriteria ${i + 1}`" />
                    <input v-model.number="c.max" type="number" min="1" max="100" class="h-10 w-20 rounded-xl border-2 border-line bg-surface px-2 text-right" :aria-label="`Skor maksimal kriteria ${i + 1}`" />
                    <button type="button" class="grid size-8 place-items-center rounded-full text-muted hover:bg-surface2" :aria-label="`Hapus kriteria ${i + 1}`" @click="removeCriterion(i)">✕</button>
                </div>
                <div class="flex items-center justify-between">
                    <button v-if="(sf.rubric ?? []).length < 6" type="button" class="text-sm font-bold text-accent" data-testid="add-criterion" @click="addCriterion">+ Tambah kriteria</button>
                    <span v-if="(sf.rubric ?? []).length" class="text-xs text-muted">Total skor maks.: {{ rubricMax() }}</span>
                </div>
            </fieldset>

            <Btn type="submit" block size="lg" :loading="sf.processing">Simpan pengaturan</Btn>

            <div class="flex flex-wrap justify-between gap-2 border-t border-line pt-4">
                <Btn v-if="can?.own" variant="soft" size="sm" @click="newCode">🔄 Ganti kode papan</Btn>
                <Btn v-if="can?.own" variant="soft" size="sm" @click="archive">{{ board.archived ? '♻️ Pulihkan dari arsip' : '🗄️ Arsipkan' }}</Btn>
                <Btn v-if="can?.own" variant="danger" size="sm" @click="removeBoard">🗑️ Hapus papan</Btn>
            </div>
        </form>
    </Modal>
</template>
