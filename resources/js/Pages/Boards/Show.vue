<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PostCard from '@/Components/Board/PostCard.vue';
import BoardSettings from '@/Components/Board/BoardSettings.vue';
import PostComposer from '@/Components/Board/PostComposer.vue';
import MediaViewer from '@/Components/Board/MediaViewer.vue';
import PostDetail from '@/Components/Board/PostDetail.vue';
import ShareDialog from '@/Components/Board/ShareDialog.vue';
import Collaborators from '@/Components/Board/Collaborators.vue';
import ActivityPanel from '@/Components/Activity/ActivityPanel.vue';
import ActivityLauncher from '@/Components/Activity/ActivityLauncher.vue';
import { BACKGROUNDS } from '@/lib/backgrounds';
import { askConfirm } from '@/lib/confirm';
import { copyText } from '@/lib/clipboard';
import { toast } from '@/lib/toast';
import { useBoardSync } from '@/lib/useBoardSync';
import { vSortable } from '@/lib/sortable';
import { getJson, postJson } from '@/lib/http';
import Modal from '@/Components/Modal.vue';
import CopyTools from '@/Components/Board/CopyTools.vue';
import { fmtDateTime } from '@/lib/datetime';
import { timeAgo } from '@/lib/theme';

const props = defineProps({
    board: Object, posts: Array, sections: Array, can: Object, version: String, settings: Object, options: Object,
    activity: Object, activity_channel: String, quick_comments: Array,
});

// Realtime lewat WebSocket (Reverb); bila tidak tersambung, cek ringan tiap ~2 detik.
useBoardSync(() => props.board.id, () => props.version, ['posts', 'sections', 'board', 'can', 'version', 'theme', 'settings'], () => props.activity_channel);

// Salinan lokal agar hasil geser langsung terlihat sebelum server menjawab
const items = ref([...props.posts]);
const cols = ref([...props.sections]);
watch(() => props.posts, (now, before) => {
    items.value = [...now];

    // Kartu baru dan sematan tidak memunculkan notifikasi (cukup animasi di daftar).
    // Satu pengecualian: komentar BARU dari orang lain pada kartu milik sendiri.
    if (!before) return;
    const prev = new Map(before.map((p) => [p.id, p]));
    const fresh = (key) => now.filter((p) => {
        const old = prev.get(p.id);
        return old && p.is_mine && (p[key] ?? 0) > (old[key] ?? 0) && detailId.value !== p.id;
    });
    const comments = fresh('others_comments');
    const reacts = fresh('others_reactions');
    const reactNew = { like: '♥ baru', star: 'Bintang baru', vote: 'Suara baru', grade: 'Nilai baru' }[props.board.reaction] ?? 'Reaksi baru';
    const first = (comments[0] ?? reacts[0])?.id;
    if (!first) return;
    const n = new Set([...comments, ...reacts].map((p) => p.id)).size;
    const what = comments.length && reacts.length ? 'Komentar dan reaksi baru' : comments.length ? 'Komentar baru' : reactNew;
    toast(`${what} di ${n === 1 ? 'kartumu' : n + ' kartumu'}`, 'success', null, { label: 'Lihat', run: () => (detailId.value = first) });
});
watch(() => props.sections, (v) => (cols.value = [...v]));

const isStudent = computed(() => usePage().props.auth?.user?.role === 'student');
const back = computed(() => (isStudent.value
    ? { href: route('student.dashboard'), label: 'Beranda' }
    : { href: route('teacher.dashboard'), label: 'Papan Saya' }));

const format = computed(() => props.board.format);
const isShelf = computed(() => format.value === 'shelf');
const manual = computed(() => props.board.sort === 'manual');
const canDrag = computed(() => props.can.manage && (manual.value || isShelf.value));
const count = computed(() => items.value.length);

const palette = ['border-t-accent', 'border-t-accent2', 'border-t-accent3', 'border-t-warning'];
const tone = (i) => palette[i % palette.length];

// Kolom Shelf: kartu tanpa kolom masuk kolom pertama
const grouped = computed(() => {
    const first = cols.value[0]?.id ?? null;
    const map = Object.fromEntries(cols.value.map((c) => [c.id, []]));
    for (const p of items.value) {
        const key = p.section_id && map[p.section_id] ? p.section_id : first;
        if (key) map[key].push(p);
    }
    return map;
});

// ---------- Tempel kartu
const open = ref(false);
const detailId = ref(null);
const editing_ = ref(null);
const composeSection = ref(null);
const compose = (sectionId = null) => {
    editing_.value = null;
    composeSection.value = sectionId;
    open.value = true;
};
const edit = (p) => {
    detailId.value = null;
    editing_.value = p;
    open.value = true;
};
const media = ref(null);
// Dari notifikasi "kartumu dinilai": buka kartunya langsung
onMounted(() => {
    const id = new URLSearchParams(location.search).get('kartu');
    if (id && items.value.some((p) => p.id === id)) detailId.value = id;
});
const tool = ref(null);
const detail = computed(() => items.value.find((p) => p.id === detailId.value) ?? null);
watch(detail, (d) => { if (detailId.value && !d) detailId.value = null; });
const cardProps = computed(() => ({
    moderate: props.can.moderate,
    manage: props.can.manage, boardId: props.board.id, interact: props.can.interact, footer: true, allowComments: props.board.allow_comments,
}));

// ---------- Aksi kartu
const remove = async (p) => {
    const ok = await askConfirm({ title: 'Hapus kartu ini?', message: 'Kartu yang dihapus tidak bisa dikembalikan.', confirmText: 'Hapus', tone: 'danger' });
    if (ok) router.delete(route('posts.destroy', [props.board.id, p.id]), { preserveScroll: true });
};
// ---------- Moderasi
const moderatePost = async (p, action) => {
    if (action === 'reject') {
        const ok = await askConfirm({ title: 'Tolak kartu ini?', message: 'Kartu dihapus dan tidak bisa dikembalikan.', confirmText: 'Tolak', tone: 'danger' });
        if (!ok) return;
    }
    router.post(route('moderation.post', [props.board.id, p.id]), { action }, { preserveScroll: true });
};
const reporting = ref(null);
const reportReason = ref('');
const reportPost = (p) => { reporting.value = p; reportReason.value = ''; };
const sendReport = () => router.post(route('posts.report', [props.board.id, reporting.value.id]), { reason: reportReason.value }, {
    preserveScroll: true, onSuccess: () => (reporting.value = null),
});
const pendingCount = computed(() => items.value.filter((p) => p.status === 'pending').length);
const queueOpen = ref(false);
const queue = ref({ comments: [], reports: {} });
const loadQueue = async () => {
    queueOpen.value = true;
    try { queue.value = await getJson(route('moderation.queue', props.board.id)); } catch (e) { toast(e.message, 'error'); }
};
const moderateComment = async (c, action) => {
    try {
        await postJson(route('moderation.comment', [props.board.id, c.id]), { action });
        queue.value.comments = queue.value.comments.filter((x) => x.id !== c.id);
        router.reload({ only: ['posts', 'version'] });
    } catch (e) { toast(e.message, 'error'); }
};

const pin = (p) => router.post(route('posts.pin', [props.board.id, p.id]), {}, { preserveScroll: true });

// ---------- Geser kartu
const onMovePost = ({ id, toKey, newIndex }) => {
    const moving = items.value.find((p) => p.id === id);
    if (!moving) return;

    const targetSection = isShelf.value ? toKey : moving.section_id;
    const list = (isShelf.value ? grouped.value[targetSection] ?? [] : items.value).filter((p) => p.id !== id);
    const after = list[newIndex - 1] ?? null;
    const before = list[newIndex] ?? null;

    // Susun ulang lokal
    const rest = items.value.filter((p) => p.id !== id);
    const updated = { ...moving, section_id: targetSection };
    let at = before ? rest.findIndex((p) => p.id === before.id) : after ? rest.findIndex((p) => p.id === after.id) + 1 : rest.length;
    if (at < 0) at = rest.length;
    rest.splice(at, 0, updated);
    items.value = rest;

    const payload = { section_id: targetSection };
    if (manual.value) Object.assign(payload, { after_id: after?.id ?? null, before_id: before?.id ?? null });
    router.post(route('posts.move', [props.board.id, id]), payload, { preserveScroll: true, preserveState: true });
};

// ---------- Kolom (Shelf)
const newCol = ref('');
const addingCol = ref(false);
const addCol = () => {
    if (!newCol.value.trim()) return;
    router.post(route('sections.store', props.board.id), { title: newCol.value }, {
        preserveScroll: true,
        onSuccess: () => { newCol.value = ''; addingCol.value = false; },
    });
};
const editing = ref(null);
const editTitle = ref('');
const startRename = (c) => { editing.value = c.id; editTitle.value = c.title; };
const rename = (c) => {
    const title = editTitle.value.trim();
    editing.value = null;
    if (title && title !== c.title) router.put(route('sections.update', [props.board.id, c.id]), { title }, { preserveScroll: true });
};
const removeCol = async (c) => {
    if (cols.value.length <= 1) return toast('Papan Shelf butuh minimal satu kolom.', 'error');
    const ok = await askConfirm({ title: `Hapus kolom "${c.title}"?`, message: 'Kartu di kolom ini dipindah ke kolom lain, tidak ikut terhapus.', confirmText: 'Hapus kolom', tone: 'danger' });
    if (ok) router.delete(route('sections.destroy', [props.board.id, c.id]), { preserveScroll: true });
};
const onMoveCol = ({ id, newIndex }) => {
    const rest = cols.value.filter((c) => c.id !== id);
    const moving = cols.value.find((c) => c.id === id);
    const after = rest[newIndex - 1] ?? null;
    const before = rest[newIndex] ?? null;
    rest.splice(newIndex, 0, moving);
    cols.value = rest;
    router.post(route('sections.move', [props.board.id, id]), { after_id: after?.id ?? null, before_id: before?.id ?? null }, { preserveScroll: true, preserveState: true });
};

// ---------- Bilah pengelola
const settingsOpen = ref(false);
const shareOpen = ref(false);
const collabOpen = ref(false);
const activityOpen = ref(false);
const bgCss = computed(() => BACKGROUNDS[props.board.background]?.css ?? null);

// Simpan papan sebagai gambar PNG (SHR-04)
const boardEl = ref(null);
const exportPng = async () => {
    shareOpen.value = false;
    toast('Menyiapkan gambar…');
    try {
        const { toPng } = await import('html-to-image');
        const bg = getComputedStyle(document.documentElement).getPropertyValue('--m-bg').trim() || '#ffffff';
        const url = await toPng(boardEl.value, { backgroundColor: bg, pixelRatio: 2, cacheBust: true, filter: (n) => !(n.dataset && n.dataset.noExport !== undefined) });
        const a = document.createElement('a');
        a.href = url;
        a.download = `papan-${props.board.code}.png`;
        a.click();
    } catch (e) {
        toast('Gagal membuat gambar. Coba Cetak / PDF.', 'error');
    }
};
const formatIcons = { wall: '🧱', grid: '▦', stream: '📜', shelf: '🗂️' };
const setLayout = (data) => router.put(route('boards.update', props.board.id), { quick: 1, ...data }, { preserveScroll: true });

const copied = ref(false);
const copyCode = async () => {
    const ok = await copyText(props.board.code);
    if (ok) {
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
        toast(`Kode ${props.board.code} tersalin`);
    } else {
        toast('Gagal menyalin. Salin kode secara manual.', 'error');
    }
};
</script>

<template>
    <Head :title="board.title" />
    <AppLayout wide :back="back">
        <div v-if="bgCss" aria-hidden="true" class="pointer-events-none fixed inset-0 -z-[5]" :style="{ background: bgCss }"></div>
        <div ref="boardEl">
        <section class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-bold text-muted">
                    {{ [board.visibility_label, board.class_name, `oleh ${board.owner}`].filter(Boolean).join(' · ') }}
                </p>
                <h1 class="text-3xl leading-tight font-bold sm:text-4xl"><span v-if="board.icon" class="mr-2">{{ board.icon }}</span>{{ board.title }}</h1>
                <p v-if="board.description" class="mt-1 max-w-2xl whitespace-pre-line text-muted">{{ board.description }}</p>
            </div>
            <div v-if="can.manage" class="flex items-stretch gap-2">
                <button type="button" class="rounded-2xl border-2 border-dashed px-4 py-2 text-center transition active:scale-95"
                    :class="copied ? 'border-success' : 'border-line'" title="Salin kode" @click="copyCode">
                    <span class="block text-xs font-bold text-muted uppercase">Kode papan</span>
                    <span class="font-mono text-3xl font-bold tracking-[0.25em]">{{ board.code }}</span>
                    <span class="block text-xs" :class="copied ? 'font-bold text-success' : 'text-muted'">{{ copied ? '✓ Tersalin!' : 'ketuk untuk menyalin' }}</span>
                </button>
                <div class="flex flex-col gap-2" data-no-export>
                    <Btn variant="soft" class="flex-1" aria-label="Pengaturan papan" @click="settingsOpen = true">⚙️<span class="hidden sm:inline">Pengaturan</span></Btn>
                    <Btn variant="soft" class="flex-1" aria-label="Bagikan dan ekspor" @click="shareOpen = true">📤<span class="hidden sm:inline">Bagikan</span></Btn>
                </div>
            </div>
            <Btn v-else variant="soft" data-no-export aria-label="Bagikan dan ekspor" @click="shareOpen = true">📤 Bagikan</Btn>
        </section>

        <p v-if="board.archived" class="mb-4 rounded-xl bg-surface2 px-4 py-2 font-bold text-muted" data-no-export>🗄️ Papan ini diarsipkan: hanya bisa dibaca.</p>

        <!-- Bilah format dan urutan (pengelola) -->
        <div v-if="can.manage" class="mb-5 flex flex-wrap items-center gap-2" data-no-export>
            <div class="flex rounded-xl bg-surface2 p-1" role="radiogroup" aria-label="Format papan">
                <button v-for="(label, key) in options.formats" :key="key" type="button" role="radio" :aria-checked="format === key"
                    class="rounded-lg px-3 py-1.5 text-sm font-bold transition" :class="format === key ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'"
                    :title="label" @click="format !== key && setLayout({ format: key })">
                    <span aria-hidden="true">{{ formatIcons[key] }}</span> <span class="hidden sm:inline">{{ label.split(' (')[0] }}</span>
                </button>
            </div>
            <select :value="board.sort" class="h-10 rounded-xl border-2 border-line bg-surface px-3 text-sm font-semibold text-ink" aria-label="Urutan kartu"
                @change="setLayout({ sort: $event.target.value })">
                <option v-for="(label, key) in options.sorts" :key="key" :value="key">{{ label }}</option>
            </select>
            <button v-if="can.own" type="button" class="h-10 rounded-xl bg-surface2 px-3 text-sm font-bold hover:bg-line/60" @click="collabOpen = true">👥 Kolaborator</button>
            <button v-if="!board.archived" type="button" class="h-10 rounded-xl bg-accent/15 px-3 text-sm font-bold text-ink hover:bg-accent/25" data-testid="open-activity" @click="activityOpen = true">🎯 Aktivitas live</button>
            <Link :href="route('boards.projector', board.id)" class="inline-flex h-10 items-center rounded-xl bg-surface2 px-3 text-sm font-bold hover:bg-line/60">📽️ Proyektor</Link>
            <span v-if="canDrag" class="text-sm text-muted">↕ Tahan lalu geser kartu untuk memindah.</span>
        </div>

        <!-- Antrean moderasi -->
        <button v-if="can.moderate" data-no-export type="button" class="mb-4 flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold transition"
            :class="pendingCount ? 'bg-warning/15 text-warning' : 'bg-surface2 text-muted hover:text-ink'" @click="loadQueue">
            🛡️ Moderasi <span v-if="pendingCount" class="rounded-full bg-warning px-2 text-bg">{{ pendingCount }} kartu menunggu</span>
        </button>

        <p v-if="board.is_frozen" class="mb-4 rounded-xl bg-warning/15 px-4 py-2 font-bold text-warning">❄️ Papan sedang dibekukan guru.</p>
        <p v-if="board.schedule === 'before'" class="mb-4 rounded-xl bg-accent/15 px-4 py-2 font-bold" data-testid="schedule-banner">⏰ Papan dibuka untuk siswa {{ fmtDateTime(board.opens_at) }}{{ can.manage ? ' — siswa belum bisa melihat kartu.' : '.' }}</p>
        <p v-else-if="board.schedule === 'after'" class="mb-4 rounded-xl bg-surface2 px-4 py-2 font-bold text-muted" data-testid="schedule-banner">🔒 Papan ditutup sejak {{ fmtDateTime(board.closes_at) }}: hanya bisa dibaca.</p>
        <p v-else-if="board.closes_at" class="mb-4 rounded-xl bg-surface2 px-4 py-2 text-sm font-bold text-muted" data-testid="schedule-banner">⏰ Ditutup otomatis {{ fmtDateTime(board.closes_at) }}.</p>
        <p v-if="board.rubric?.length && can.manage" class="mb-4 text-sm text-muted">📊 Rubrik aktif ({{ board.rubric.map((c) => c.name).join(', ') }}). Buka kartu siswa untuk menilai.</p>

        <!-- Aktivitas live: timer, poll, word cloud, spin wheel -->
        <ActivityPanel :board-id="board.id" :initial="activity" :channel="activity_channel" :can-interact="can.interact" />

        <EmptyState v-if="board.schedule === 'before' && !can.manage" title="Papan belum dibuka" :text="`Gurumu menjadwalkan papan ini dibuka ${fmtDateTime(board.opens_at)}. Kembali lagi nanti, ya!`" />
        <EmptyState v-else-if="!count && !isShelf" title="Papan masih kosong" :text="can.post ? 'Jadilah yang pertama menempel kartu!' : 'Belum ada kartu.'">
            <Btn v-if="can.post" @click="compose()">+ Tempel kartu</Btn>
        </EmptyState>

        <!-- Wall: susunan bata -->
        <TransitionGroup v-else-if="format === 'wall'" v-sortable="{ disabled: !canDrag, onMove: onMovePost }" tag="div" name="card" class="columns-1 gap-4 sm:columns-2 lg:columns-3 xl:columns-4">
            <div v-for="(p, i) in items" :key="p.id" :data-id="p.id" class="mb-4 break-inside-avoid">
                <PostCard :post="p" :tone="tone(i)" v-bind="cardProps" :draggable="canDrag" @pin="pin" @remove="remove" @edit="edit" @open-media="media = $event" @open="detailId = $event.id" @moderate="moderatePost" @report="reportPost" />
            </div>
        </TransitionGroup>

        <!-- Grid: kotak sama tinggi -->
        <TransitionGroup v-else-if="format === 'grid'" v-sortable="{ disabled: !canDrag, onMove: onMovePost }" tag="div" name="card" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div v-for="(p, i) in items" :key="p.id" :data-id="p.id" class="[&>article]:h-full">
                <PostCard :post="p" :tone="tone(i)" v-bind="cardProps" :draggable="canDrag" compact @pin="pin" @remove="remove" @edit="edit" @open-media="media = $event" @open="detailId = $event.id" @moderate="moderatePost" @report="reportPost" />
            </div>
        </TransitionGroup>

        <!-- Stream: satu kolom, nyaman di HP -->
        <TransitionGroup v-else-if="format === 'stream'" v-sortable="{ disabled: !canDrag, onMove: onMovePost }" tag="div" name="card" class="mx-auto max-w-2xl space-y-4">
            <div v-for="(p, i) in items" :key="p.id" :data-id="p.id">
                <PostCard :post="p" :tone="tone(i)" v-bind="cardProps" :draggable="canDrag" @pin="pin" @remove="remove" @edit="edit" @open-media="media = $event" @open="detailId = $event.id" @moderate="moderatePost" @report="reportPost" />
            </div>
        </TransitionGroup>

        <!-- Shelf: kolom-kolom bergeser ke samping -->
        <div v-else class="-mx-4 overflow-x-auto px-4 pb-4">
            <div v-sortable="{ disabled: !can.manage, handle: '.col-handle', onMove: onMoveCol }" class="flex items-start gap-4">
                <section v-for="c in cols" :key="c.id" :data-id="c.id" class="flex w-72 shrink-0 flex-col rounded-card bg-surface2/60 p-3 sm:w-80">
                    <header class="mb-3 flex items-center gap-2">
                        <span v-if="can.manage" class="col-handle cursor-grab text-muted select-none" title="Geser kolom" aria-hidden="true">⠿</span>
                        <input v-if="editing === c.id" v-model="editTitle" maxlength="100" autofocus
                            class="h-9 min-w-0 flex-1 rounded-lg border-2 border-accent bg-surface px-2 font-bold text-ink"
                            @keydown.enter.prevent="rename(c)" @keydown.esc="editing = null" @blur="rename(c)" />
                        <h2 v-else class="min-w-0 flex-1 truncate text-lg font-bold" :class="can.manage ? 'cursor-text' : ''" @dblclick="can.manage && startRename(c)">{{ c.title }}</h2>
                        <span class="rounded-full bg-surface px-2 text-xs font-bold text-muted">{{ grouped[c.id]?.length ?? 0 }}</span>
                        <template v-if="can.manage">
                            <button type="button" class="grid size-7 place-items-center rounded-full text-sm hover:bg-surface" title="Ganti nama" @click="startRename(c)">✏️</button>
                            <button type="button" class="grid size-7 place-items-center rounded-full text-sm hover:bg-surface" title="Hapus kolom" @click="removeCol(c)">🗑️</button>
                        </template>
                    </header>
                    <TransitionGroup v-sortable="{ group: 'shelf', disabled: !canDrag, onMove: onMovePost }" tag="div" name="card" :data-key="c.id" class="flex min-h-16 flex-col gap-3">
                        <div v-for="(p, i) in grouped[c.id]" :key="p.id" :data-id="p.id">
                            <PostCard :post="p" :tone="tone(i)" v-bind="cardProps" :draggable="canDrag" compact @pin="pin" @remove="remove" @edit="edit" @open-media="media = $event" @open="detailId = $event.id" @moderate="moderatePost" @report="reportPost" />
                        </div>
                    </TransitionGroup>
                    <button v-if="can.post" type="button" class="mt-3 rounded-xl border-2 border-dashed border-line py-2 font-bold text-muted hover:border-accent hover:text-ink" @click="compose(c.id)">+ Tambah kartu</button>
                </section>

                <div v-if="can.manage" class="w-72 shrink-0">
                    <form v-if="addingCol" class="rounded-card bg-surface2/60 p-3" @submit.prevent="addCol">
                        <input v-model="newCol" maxlength="100" placeholder="Judul kolom" autofocus class="h-11 w-full rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
                        <div class="mt-2 flex gap-2"><Btn type="submit" size="sm">Tambah</Btn><Btn size="sm" variant="ghost" @click="addingCol = false">Batal</Btn></div>
                    </form>
                    <button v-else type="button" class="w-full rounded-card border-2 border-dashed border-line py-4 font-bold text-muted hover:border-accent hover:text-ink" @click="addingCol = true">+ Kolom baru</button>
                </div>
            </div>
        </div>

        </div>

        <!-- Tombol tempel kartu -->
        <button v-if="can.post && (count || isShelf)" @click="compose(isShelf ? cols[0]?.id : null)"
            class="fixed right-5 bottom-24 z-20 grid size-16 place-items-center rounded-full bg-accent text-4xl font-bold text-on-accent shadow-card transition hover:scale-105 active:scale-95 lg:bottom-8"
            aria-label="Tempel kartu">+</button>

        <PostComposer :show="open" :board="board" :sections="cols" :post="editing_" :section-id="composeSection" @close="open = false" />
        <PostDetail :post="detail" :board="board" :can="can" :quick="quick_comments" @close="detailId = null" @edit="edit" @pin="pin" @remove="remove" @open-media="media = $event"
            @moderate="moderatePost" @report="(p) => { detailId = null; reportPost(p); }" />
        <MediaViewer :item="media" @close="media = null" />

        <Modal :show="!!reporting" title="Laporkan kartu" @close="reporting = null">
            <form class="space-y-4" @submit.prevent="sendReport">
                <p class="text-muted">Laporan dikirim ke guru tanpa diketahui penulis kartu. Kartu yang dilaporkan beberapa siswa ditahan sampai diperiksa guru.</p>
                <textarea v-model="reportReason" rows="3" maxlength="200" placeholder="Alasan (opsional), mis. mengejek teman"
                    class="w-full rounded-xl border-2 border-line bg-surface px-3 py-2 text-ink focus:border-accent focus:outline-none"></textarea>
                <Btn type="submit" block variant="danger">🚩 Kirim laporan</Btn>
            </form>
        </Modal>

        <Modal :show="queueOpen" title="Moderasi" @close="queueOpen = false">
            <div class="space-y-5">
                <section>
                    <h3 class="mb-2 font-bold">Kartu menunggu ({{ pendingCount }})</h3>
                    <p v-if="!pendingCount" class="text-sm text-muted">Tidak ada kartu yang ditahan.</p>
                    <ul class="space-y-2">
                        <li v-for="p in items.filter((x) => x.status === 'pending')" :key="p.id" class="rounded-xl bg-surface2/70 p-3">
                            <p class="text-xs font-bold text-muted">{{ p.author }} · {{ p.moderation_reason }}</p>
                            <p class="font-bold">{{ p.title || '(tanpa judul)' }}</p>
                            <ul v-if="queue.reports[p.id]" class="mt-1 text-xs text-danger">
                                <li v-for="(r, i) in queue.reports[p.id]" :key="i">🚩 {{ r.by }}{{ r.reason ? ': ' + r.reason : '' }}</li>
                            </ul>
                            <div class="mt-2 flex gap-2">
                                <Btn size="sm" @click="moderatePost(p, 'approve')">✓ Setujui</Btn>
                                <Btn size="sm" variant="soft" @click="queueOpen = false; detailId = p.id">Lihat</Btn>
                                <Btn size="sm" variant="danger" @click="moderatePost(p, 'reject')">✕ Tolak</Btn>
                            </div>
                        </li>
                    </ul>
                </section>
                <section>
                    <h3 class="mb-2 font-bold">Komentar menunggu ({{ queue.comments.length }})</h3>
                    <p v-if="!queue.comments.length" class="text-sm text-muted">Tidak ada komentar yang ditahan.</p>
                    <ul class="space-y-2">
                        <li v-for="c in queue.comments" :key="c.id" class="rounded-xl bg-surface2/70 p-3">
                            <p class="text-xs font-bold text-muted">{{ c.author }} di "{{ c.post_title || 'kartu' }}" · {{ timeAgo(c.created_at) }} · {{ c.reason }}</p>
                            <p class="whitespace-pre-line">{{ c.body }}</p>
                            <div class="mt-2 flex gap-2">
                                <Btn size="sm" @click="moderateComment(c, 'approve')">✓ Setujui</Btn>
                                <Btn size="sm" variant="danger" @click="moderateComment(c, 'reject')">✕ Tolak</Btn>
                            </div>
                        </li>
                    </ul>
                </section>
                <section v-if="Object.keys(queue.reports).length">
                    <h3 class="mb-2 font-bold">Kartu dilaporkan</h3>
                    <ul class="space-y-2">
                        <li v-for="(rs, pid) in queue.reports" :key="pid" class="rounded-xl bg-surface2/70 p-3 text-sm">
                            <p class="font-bold">{{ items.find((x) => x.id === pid)?.title || 'Kartu' }}</p>
                            <p v-for="(r, i) in rs" :key="i" class="text-danger">🚩 {{ r.by }}{{ r.reason ? ': ' + r.reason : '' }}</p>
                            <div class="mt-2 flex gap-2">
                                <Btn size="sm" variant="soft" @click="moderatePost({ id: pid }, 'approve')">Abaikan laporan</Btn>
                                <Btn size="sm" variant="soft" @click="moderatePost({ id: pid }, 'hide')">🙈 Sembunyikan</Btn>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </Modal>

        <ShareDialog :show="shareOpen" :board="board" :can="can" :secret-url="settings?.secret_url" @close="shareOpen = false" @png="exportPng" @tool="(m) => { shareOpen = false; tool = m; }" />
        <CopyTools v-if="can.manage" :mode="tool" :board="{ ...board, class_id: settings?.class_id }" :classes="options?.classes ?? []" @close="tool = null" />
        <ActivityLauncher v-if="can.manage" :show="activityOpen" :board-id="board.id" :has-class="!!board.class_name" @close="activityOpen = false" />
        <Collaborators v-if="can.own" :show="collabOpen" :board-id="board.id" @close="collabOpen = false" />

        <BoardSettings v-if="can.manage" :show="settingsOpen" :board="board" :settings="settings" :options="options" :can="can" @close="settingsOpen = false" />
    </AppLayout>
</template>
