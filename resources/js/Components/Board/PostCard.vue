<script setup>
import { computed, ref } from 'vue';
import { timeAgo } from '@/lib/theme';
import { cardStyle } from '@/lib/colors';
import { formatBytes } from '@/lib/http';
import LinkPreview from '@/Components/Board/LinkPreview.vue';
import Reactions from '@/Components/Board/Reactions.vue';

const props = defineProps({
    post: Object,
    tone: String,
    manage: Boolean,
    draggable: Boolean,
    compact: Boolean,
    boardId: String,
    interact: Boolean,
    footer: Boolean, // tampilkan reaksi + tombol komentar
    allowComments: Boolean,
    moderate: Boolean,
});
const emit = defineEmits(['pin', 'remove', 'edit', 'open-media', 'open', 'moderate', 'report']);
const reasonLabel = { 'kata tidak pantas': 'ada kata kurang pantas', 'perlu persetujuan': 'menunggu persetujuan', 'banyak dilaporkan': 'banyak dilaporkan', 'disembunyikan': 'disembunyikan' };
const menu = ref(false);
const close = () => (menu.value = false);

const images = computed(() => (props.post.attachments ?? []).filter((a) => a.kind === 'image'));
const others = computed(() => (props.post.attachments ?? []).filter((a) => a.kind !== 'image'));
const hasMenu = computed(() => props.manage || props.moderate || props.post.can_delete || props.post.can_edit || props.post.can_report);
const fileIcon = (a) => ({ audio: '🎵', video: '🎬' }[a.kind] || (a.mime === 'application/pdf' ? '📄' : /sheet|excel/.test(a.mime) ? '📊' : /presentation|powerpoint/.test(a.mime) ? '📽️' : /word|text/.test(a.mime) ? '📝' : '📎'));
const viewable = (a) => ['audio', 'video'].includes(a.kind) || a.mime === 'application/pdf';
</script>

<template>
    <article class="group relative rounded-card border border-t-8 border-line bg-surface p-4 shadow-card"
        :class="[post.color ? '' : tone, post.is_pinned ? 'ring-2 ring-accent' : '', draggable ? 'cursor-grab active:cursor-grabbing' : '', post.status === 'hidden' ? 'opacity-60' : '', post.status === 'pending' ? 'border-dashed !border-warning' : '']"
        :style="cardStyle(post.color)">
        <div class="mb-2 flex items-center justify-between gap-2 text-xs font-bold text-muted">
            <span class="min-w-0 truncate">
                <span v-if="post.is_pinned" title="Disematkan">📌 </span>
                <span :class="post.is_teacher ? 'text-ink' : ''">{{ post.author }}</span><span v-if="post.is_teacher"> · guru</span>
                <span v-if="post.is_anonymous && post.author !== 'Anonim'" class="ml-1 rounded bg-surface2 px-1.5 py-0.5 text-[10px] uppercase">anonim</span>
            </span>
            <span class="flex shrink-0 items-center gap-1">
                <span :title="post.edited ? 'Diubah' : ''">{{ timeAgo(post.created_at) }}{{ post.edited ? ' · diubah' : '' }}</span>
                <button v-if="hasMenu" type="button" class="-mr-1 grid size-7 place-items-center rounded-full text-base hover:bg-surface2"
                    aria-label="Menu kartu" :aria-expanded="menu" @click.stop="menu = !menu">⋯</button>
            </span>
        </div>

        <!-- Status moderasi -->
        <div v-if="post.status && post.status !== 'published'" class="mb-3 rounded-xl px-3 py-2 text-xs font-bold"
            :class="post.status === 'pending' ? 'bg-warning/15 text-warning' : 'bg-surface2 text-muted'">
            <p>{{ post.status === 'pending' ? '⏳ Ditahan' : '🙈 Disembunyikan' }}<span v-if="post.moderation_reason"> · {{ reasonLabel[post.moderation_reason] ?? post.moderation_reason }}</span></p>
            <div v-if="moderate" class="mt-2 flex gap-2">
                <button v-if="post.status === 'pending'" type="button" class="rounded-lg bg-success px-3 py-1 text-bg" @click.stop="emit('moderate', post, 'approve')">✓ Setujui</button>
                <button v-if="post.status === 'hidden'" type="button" class="rounded-lg bg-accent px-3 py-1 text-on-accent" @click.stop="emit('moderate', post, 'unhide')">Tampilkan</button>
                <button type="button" class="rounded-lg bg-danger/15 px-3 py-1 text-danger" @click.stop="emit('moderate', post, 'reject')">✕ Tolak</button>
            </div>
            <p v-else-if="post.status === 'pending'" class="mt-1 font-semibold">Hanya kamu dan guru yang bisa melihat kartu ini.</p>
        </div>
        <p v-if="moderate && post.report_count" class="mb-2 inline-block rounded-full bg-danger/15 px-2 py-0.5 text-xs font-bold text-danger">🚩 {{ post.report_count }} laporan</p>

        <div v-if="menu" class="absolute top-10 right-3 z-10 w-48 rounded-xl border border-line bg-surface p-1 text-sm font-semibold shadow-card" @mouseleave="close">
            <button v-if="post.can_edit" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="close(); emit('edit', post)">✏️ Ubah kartu</button>
            <button v-if="manage" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="close(); emit('pin', post)">
                {{ post.is_pinned ? '📌 Lepas sematan' : '📌 Sematkan di atas' }}
            </button>
            <button v-if="moderate && post.status === 'published'" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="close(); emit('moderate', post, 'hide')">🙈 Sembunyikan</button>
            <button v-if="post.can_report" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-surface2" @click="close(); emit('report', post)">🚩 Laporkan</button>
            <button v-if="post.can_delete" class="block w-full rounded-lg px-3 py-2 text-left text-danger hover:bg-surface2" @click="close(); emit('remove', post)">🗑️ Hapus kartu</button>
        </div>

        <!-- Gambar -->
        <div v-if="images.length" class="-mx-1 mb-3 grid gap-1" :class="images.length === 1 ? 'grid-cols-1' : 'grid-cols-2'">
            <button v-for="(img, i) in images.slice(0, 4)" :key="img.id" type="button" class="relative overflow-hidden rounded-lg bg-surface2"
                :class="images.length === 3 && i === 0 ? 'col-span-2' : ''" :aria-label="`Lihat ${img.name}`" @click="emit('open-media', img)">
                <img :src="img.thumb_url || img.url" :alt="img.name" loading="lazy"
                    class="w-full object-cover" :class="images.length === 1 ? 'max-h-96' : 'aspect-square'"
                    :style="images.length === 1 && img.width ? { aspectRatio: `${img.width} / ${img.height}` } : {}" />
                <span v-if="i === 3 && images.length > 4" class="absolute inset-0 grid place-items-center bg-black/55 text-2xl font-bold text-white">+{{ images.length - 4 }}</span>
            </button>
        </div>

        <h3 v-if="post.title" class="text-lg leading-snug font-bold break-words">{{ post.title }}</h3>
        <!-- HTML sudah dibersihkan server (HtmlSanitizer, daftar putih) -->
        <div v-if="post.body" class="rich mt-1 break-words" :class="compact ? 'line-clamp-6' : ''" v-html="post.body"></div>

        <LinkPreview v-if="post.link" :link="post.link" class="mt-3" />

        <ul v-if="others.length" class="mt-3 space-y-1.5">
            <li v-for="a in others" :key="a.id">
                <audio v-if="a.kind === 'audio'" :src="a.url" controls preload="none" class="w-full"></audio>
                <component :is="viewable(a) ? 'button' : 'a'" v-else type="button" :href="viewable(a) ? undefined : a.download_url"
                    class="flex w-full items-center gap-2 rounded-xl bg-surface2/80 px-3 py-2 text-left text-sm hover:bg-surface2" @click="viewable(a) && emit('open-media', a)">
                    <span class="text-xl">{{ fileIcon(a) }}</span>
                    <span class="min-w-0 flex-1 truncate font-semibold">{{ a.name }}</span>
                    <span class="shrink-0 text-xs text-muted">{{ formatBytes(a.size) }}</span>
                </component>
            </li>
        </ul>

        <footer v-if="footer && (post.reaction || allowComments || post.comment_count)" class="mt-3 flex items-center justify-between gap-2 border-t border-line/60 pt-2" @click.stop>
            <Reactions :board-id="boardId" :post="post" :can="interact" :manage="manage" />
            <button v-if="allowComments || post.comment_count" type="button" class="ml-auto flex h-8 items-center gap-1 rounded-full px-2.5 text-sm font-bold text-muted hover:bg-surface2 hover:text-ink"
                :aria-label="`Komentar (${post.comment_count})`" @click="emit('open', post)">💬 <span v-if="post.comment_count">{{ post.comment_count }}</span></button>
        </footer>
    </article>
</template>
