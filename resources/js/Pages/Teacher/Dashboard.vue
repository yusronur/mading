<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { askConfirm } from '@/lib/confirm';
import { timeAgo } from '@/lib/theme';
import { BACKGROUNDS } from '@/lib/backgrounds';

const props = defineProps({ boards: Array, folders: Array, classes: Array, themes: Array, visibilities: Object, formats: Object });

// ---------- Buat papan
const open = ref(false);
const form = useForm({ title: '', description: '', visibility: 'class', class_id: props.classes[0]?.id ?? null, theme_id: null, theme_locked: false, format: 'wall' });
const formatCards = [
    { key: 'wall', icon: '🧱', label: 'Dinding' },
    { key: 'grid', icon: '▦', label: 'Kisi' },
    { key: 'stream', icon: '📜', label: 'Linimasa' },
    { key: 'shelf', icon: '🗂️', label: 'Berkolom' },
];
const submit = () => form.post(route('boards.store'), { onSuccess: () => { open.value = false; form.reset(); } });

// ---------- Saring
const tab = ref('mine'); // mine | shared | archived
const folder = ref(null);
const q = ref('');
const classFilter = ref('');
const formatFilter = ref('');

const list = computed(() => props.boards.filter((b) => {
    if (tab.value === 'mine' && (!b.is_owner || b.archived)) return false;
    if (tab.value === 'shared' && (b.is_owner || b.archived)) return false;
    if (tab.value === 'archived' && !b.archived) return false;
    if (tab.value === 'mine' && folder.value && b.folder_id !== folder.value) return false;
    if (classFilter.value && b.class_id !== classFilter.value) return false;
    if (formatFilter.value && b.format !== formatFilter.value) return false;
    if (q.value && !`${b.title} ${b.code} ${b.class_name ?? ''}`.toLowerCase().includes(q.value.toLowerCase())) return false;
    return true;
}));
const counts = computed(() => ({
    mine: props.boards.filter((b) => b.is_owner && !b.archived).length,
    shared: props.boards.filter((b) => !b.is_owner && !b.archived).length,
    archived: props.boards.filter((b) => b.archived).length,
}));
const inFolder = (id) => props.boards.filter((b) => b.folder_id === id && !b.archived).length;

// ---------- Folder
const folderOpen = ref(false);
const folderForm = useForm({ name: '' });
const addFolder = () => folderForm.post(route('folders.store'), { preserveScroll: true, onSuccess: () => { folderOpen.value = false; folderForm.reset(); } });
const removeFolder = async (f) => {
    if (await askConfirm({ title: `Hapus folder "${f.name}"?`, message: 'Papan di dalamnya tidak ikut terhapus.', confirmText: 'Hapus folder', tone: 'danger' })) {
        if (folder.value === f.id) folder.value = null;
        router.delete(route('folders.destroy', f.id), { preserveScroll: true });
    }
};

// ---------- Menu papan
const menuFor = ref(null);
const moving = ref(null);
const archive = (b) => router.post(route('boards.archive', b.id), { archive: !b.archived }, { preserveScroll: true });
const duplicate = (b) => router.post(route('boards.duplicate', b.id), { with_posts: false });
const moveTo = (folderId) => {
    router.post(route('boards.move-folder', moving.value.id), { folder_id: folderId }, { preserveScroll: true });
    moving.value = null;
};
const remove = async (b) => {
    if (await askConfirm({ title: `Hapus papan "${b.title}"?`, message: 'Papan dan semua kartunya tidak tampil lagi bagi siswa.', confirmText: 'Hapus', tone: 'danger' })) {
        router.delete(route('boards.destroy', b.id), { preserveScroll: true });
    }
};
const stripes = ['bg-accent', 'bg-accent2', 'bg-accent3', 'bg-warning'];
const visLabel = { private: '🔒 Pribadi', class: '🏫', school: '🌏 Sekolah', link: '🔗 Tautan' };
</script>

<template>
    <Head title="Papan saya" />
    <AppLayout title="Papan saya 📌" wide>
        <template #actions><Btn size="lg" @click="open = true">+ Papan baru</Btn></template>

        <!-- Tab -->
        <div class="mb-4 flex flex-wrap gap-2" role="tablist">
            <button v-for="t in [['mine', 'Papan saya'], ['shared', 'Dibagikan ke saya'], ['archived', 'Arsip']]" :key="t[0]" role="tab" :aria-selected="tab === t[0]"
                class="rounded-full px-4 py-2 text-sm font-bold transition" :class="tab === t[0] ? 'bg-ink text-bg' : 'bg-surface2 text-muted hover:text-ink'"
                @click="tab = t[0]">{{ t[1] }} <span class="opacity-70">{{ counts[t[0]] }}</span></button>
        </div>

        <!-- Folder -->
        <div v-if="tab === 'mine'" class="mb-4 flex flex-wrap items-center gap-2">
            <button class="rounded-xl px-3 py-1.5 text-sm font-bold" :class="!folder ? 'bg-accent text-on-accent' : 'bg-surface2'" @click="folder = null">📁 Semua</button>
            <span v-for="f in folders" :key="f.id" class="group inline-flex items-center rounded-xl text-sm font-bold" :class="folder === f.id ? 'bg-accent text-on-accent' : 'bg-surface2'">
                <button class="py-1.5 pl-3" :class="folder === f.id ? 'pr-1' : 'pr-3'" @click="folder = f.id">📂 {{ f.name }} <span class="opacity-70">{{ inFolder(f.id) }}</span></button>
                <button v-if="folder === f.id" class="px-2 py-1.5 opacity-80 hover:opacity-100" :aria-label="`Hapus folder ${f.name}`" @click="removeFolder(f)">✕</button>
            </span>
            <button class="rounded-xl border-2 border-dashed border-line px-3 py-1 text-sm font-bold text-muted hover:text-ink" @click="folderOpen = true">+ Folder</button>
        </div>

        <!-- Cari & saring -->
        <div class="mb-5 grid gap-2 sm:grid-cols-4">
            <input v-model="q" type="search" placeholder="Cari judul atau kode papan" class="h-11 rounded-xl border-2 border-line bg-surface px-4 text-ink sm:col-span-2" />
            <select v-model="classFilter" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Saring rombel">
                <option value="">Semua rombel</option>
                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="formatFilter" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Saring format">
                <option value="">Semua format</option>
                <option v-for="(label, key) in formats" :key="key" :value="key">{{ label }}</option>
            </select>
        </div>

        <EmptyState v-if="!boards.length" title="Belum ada papan" text="Buat papan pertama, lalu bagikan kodenya ke siswa. Mereka bisa langsung menempel kartu dari HP.">
            <Btn @click="open = true">Buat papan pertama</Btn>
        </EmptyState>
        <p v-else-if="!list.length" class="rounded-card bg-surface2/60 p-8 text-center text-muted">Tidak ada papan yang cocok.</p>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="(b, i) in list" :key="b.id" class="animate-pop relative" :style="{ animationDelay: `${Math.min(i, 10) * 30}ms` }">
                <Link :href="route('boards.show', b.id)" class="group relative block h-full overflow-hidden rounded-card border border-line bg-surface p-5 pr-12 shadow-card transition hover:-translate-y-1"
                    :style="BACKGROUNDS[b.background]?.css ? { background: BACKGROUNDS[b.background].css + ', var(--m-surface)' } : {}">
                    <span class="absolute inset-x-0 top-0 h-2" :class="stripes[i % stripes.length]"></span>
                    <h3 class="text-xl leading-tight font-bold group-hover:underline"><span v-if="b.icon">{{ b.icon }} </span>{{ b.title }}</h3>
                    <p class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted">
                        <span class="rounded bg-surface2 px-1.5 font-mono text-xs font-bold tracking-widest text-ink">{{ b.code }}</span>
                        <span>{{ visLabel[b.visibility] }}{{ b.class_name ? ' ' + b.class_name : '' }}</span>
                        <span>🗒️ {{ b.posts_count }}</span>
                        <span v-if="!b.is_owner">👤 {{ b.owner }}</span>
                    </p>
                    <p class="mt-1 text-xs text-muted">Diperbarui {{ timeAgo(b.updated_at) }}</p>
                </Link>
                <button type="button" class="absolute top-4 right-3 grid size-9 place-items-center rounded-full text-lg hover:bg-surface2" :aria-label="`Menu ${b.title}`"
                    @click="menuFor = menuFor === b.id ? null : b.id">⋯</button>
                <div v-if="menuFor === b.id" class="absolute top-14 right-3 z-10 w-52 rounded-xl border border-line bg-surface p-1 text-sm font-semibold shadow-card" @mouseleave="menuFor = null">
                    <button class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="menuFor = null; duplicate(b)">📑 Duplikat</button>
                    <template v-if="b.is_owner">
                        <button v-if="!b.archived" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="menuFor = null; moving = b">📂 Pindah ke folder</button>
                        <button class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="menuFor = null; archive(b)">{{ b.archived ? '♻️ Pulihkan' : '🗄️ Arsipkan' }}</button>
                        <button class="block w-full rounded-lg px-3 py-2 text-left text-danger hover:bg-surface2" @click="menuFor = null; remove(b)">🗑️ Hapus</button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal: papan baru -->
        <Modal :show="open" title="Papan baru" @close="open = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field v-model="form.title" label="Judul" placeholder="mis. Refleksi Bab 3: Ekosistem" :error="form.errors.title" maxlength="150" />
                <Field v-model="form.description" as="textarea" :rows="2" label="Petunjuk untuk siswa (opsional)" :error="form.errors.description" />
                <div>
                    <p class="mb-1.5 text-sm font-bold">Format papan</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button v-for="f in formatCards" :key="f.key" type="button" class="rounded-xl border-2 p-3 text-center font-bold transition"
                            :class="form.format === f.key ? 'border-accent bg-surface2' : 'border-line'" @click="form.format = f.key">
                            <span class="block text-2xl">{{ f.icon }}</span><span class="text-sm">{{ f.label }}</span>
                        </button>
                    </div>
                </div>
                <Field v-model="form.visibility" as="select" label="Siapa yang bisa melihat?" :error="form.errors.visibility">
                    <option v-for="(label, key) in visibilities" :key="key" :value="key">{{ label }}</option>
                </Field>
                <Field v-if="form.visibility === 'class'" v-model="form.class_id" as="select" label="Rombel" :error="form.errors.class_id">
                    <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                </Field>
                <Field v-model="form.theme_id" as="select" label="Tema papan (opsional)">
                    <option :value="null">Ikuti pilihan masing-masing siswa</option>
                    <option v-for="t in themes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </Field>
                <label v-if="form.theme_id" class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="form.theme_locked" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Kunci tema ini untuk semua siswa
                </label>
                <Btn type="submit" block size="lg" :loading="form.processing">Buat papan</Btn>
            </form>
        </Modal>

        <Modal :show="folderOpen" title="Folder baru" @close="folderOpen = false">
            <form class="space-y-4" @submit.prevent="addFolder">
                <Field v-model="folderForm.name" label="Nama folder" maxlength="60" placeholder="mis. Kelas 7 — Semester 1" :error="folderForm.errors.name" />
                <Btn type="submit" block :loading="folderForm.processing">Buat folder</Btn>
            </form>
        </Modal>

        <Modal :show="!!moving" title="Pindah ke folder" @close="moving = null">
            <div class="space-y-2">
                <button class="block w-full rounded-xl bg-surface2 px-4 py-3 text-left font-bold hover:bg-line/60" @click="moveTo(null)">📁 Tanpa folder</button>
                <button v-for="f in folders" :key="f.id" class="block w-full rounded-xl bg-surface2 px-4 py-3 text-left font-bold hover:bg-line/60" @click="moveTo(f.id)">📂 {{ f.name }}</button>
                <p v-if="!folders.length" class="text-sm text-muted">Belum ada folder. Buat dulu dengan tombol "+ Folder".</p>
            </div>
        </Modal>
    </AppLayout>
</template>
