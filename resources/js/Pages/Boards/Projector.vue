<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { applyTheme } from '@/lib/theme';
import Flash from '@/Components/Flash.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EffectsToggle from '@/Components/EffectsToggle.vue';
import PostCard from '@/Components/Board/PostCard.vue';
import ActivityPanel from '@/Components/Activity/ActivityPanel.vue';
import ActivityLauncher from '@/Components/Activity/ActivityLauncher.vue';
import { useBoardSync } from '@/lib/useBoardSync';
import { seedActivity } from '@/lib/activity';

// Layar presentasi/proyektor (BRD-20): teks besar, tanpa navigasi aplikasi.
// Aktivitas live memenuhi layar; kartu tampil besar bila tidak ada aktivitas.
const props = defineProps({ board: Object, posts: Array, sections: Array, version: String, can: Object, activity: Object, activity_channel: String });
const page = usePage();
watch(() => page.props.theme, (t) => applyTheme(t), { immediate: true, deep: true });
useBoardSync(() => props.board.id, () => props.version, ['posts', 'sections', 'board', 'version', 'theme'], () => props.activity_channel);

const activity = seedActivity(props.board.id, props.activity);
const hasActivity = computed(() => !!activity.value);
const showCards = ref(false);
const launcher = ref(false);

const posts = computed(() => props.posts.filter((p) => p.status === 'published'));
const tones = ['border-t-accent', 'border-t-accent2', 'border-t-accent3', 'border-t-warning'];

const qr = ref('');
onMounted(async () => {
    qr.value = await QRCode.toDataURL(props.board.join_url, { margin: 1, width: 260, errorCorrectionLevel: 'M' });
});
const fullscreen = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.());
</script>

<template>
    <Head :title="`Proyektor: ${board.title}`" />
    <div class="flex min-h-dvh flex-col bg-bg text-ink">
        <Flash />
        <ConfirmDialog />

        <header class="flex flex-wrap items-center gap-3 border-b border-line/60 px-4 py-3 sm:px-6">
            <Link :href="route('boards.show', board.id)" class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold text-muted hover:text-ink">← Papan</Link>
            <h1 class="min-w-0 flex-1 truncate font-display text-2xl font-bold sm:text-3xl"><span v-if="board.icon" class="mr-2">{{ board.icon }}</span>{{ board.title }}</h1>
            <button v-if="hasActivity" type="button" class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold" data-testid="toggle-cards" @click="showCards = !showCards">{{ showCards ? '🎯 Lihat aktivitas' : '🗂️ Lihat kartu' }}</button>
            <button v-if="can.manage" type="button" class="rounded-lg bg-accent/15 px-3 py-1.5 text-sm font-bold" data-testid="open-activity" @click="launcher = true">🎯 Aktivitas live</button>
            <EffectsToggle />
            <button type="button" class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold" @click="fullscreen">⛶ Layar penuh</button>
        </header>

        <main class="flex flex-1 flex-col gap-4 p-4 sm:p-6">
            <ActivityPanel v-show="hasActivity && !showCards" :board-id="board.id" :initial="activity" :channel="activity_channel" :can-interact="can.interact" big class="flex-1" />

            <template v-if="!hasActivity || showCards">
                <div v-if="!posts.length" class="m-auto text-center">
                    <p class="font-display text-5xl font-bold">Tempel kartumu!</p>
                    <p class="mt-3 text-3xl text-muted">Buka <b class="text-ink">{{ board.join_url.replace(/^https?:\/\//, '') }}</b> atau masukkan kode</p>
                    <p class="mt-2 font-mono text-8xl font-bold tracking-[0.25em] text-accent">{{ board.code }}</p>
                    <img v-if="qr" :src="qr" alt="QR untuk bergabung" class="mx-auto mt-6 w-60 rounded-2xl bg-white p-2" />
                </div>
                <!-- zoom memperbesar kartu apa adanya agar terbaca dari belakang kelas -->
                <div v-else class="columns-1 gap-5 md:columns-2 2xl:columns-3" style="zoom: 1.35" data-testid="projector-cards">
                    <div v-for="(p, i) in posts" :key="p.id" class="mb-5 break-inside-avoid">
                        <PostCard :post="p" :tone="tones[i % tones.length]" :board-id="board.id" />
                    </div>
                </div>
            </template>
        </main>

        <aside v-if="posts.length || hasActivity" class="pointer-events-none fixed right-4 bottom-4 flex items-center gap-3 rounded-2xl bg-surface/90 p-2 pr-4 shadow-card backdrop-blur">
            <img v-if="qr" :src="qr" alt="" class="size-16 rounded-lg bg-white p-1" />
            <div><p class="text-xs font-bold text-muted">Gabung dengan kode</p><p class="font-mono text-2xl font-bold tracking-[0.2em]">{{ board.code }}</p></div>
        </aside>

        <ActivityLauncher v-if="can.manage" :show="launcher" :board-id="board.id" :has-class="!!board.class_name" @close="launcher = false" />
    </div>
</template>
