<script setup>
import { Link } from '@inertiajs/vue3';
import { timeAgo } from '@/lib/theme';

const props = defineProps({ board: Object, index: { type: Number, default: 0 } });
const stripes = ['bg-accent', 'bg-accent2', 'bg-accent3', 'bg-warning'];
const visLabel = { private: '🔒 Pribadi', class: '🏫 Rombel', school: '🌏 Sekolah' };
</script>

<template>
    <Link :href="route('boards.show', board.id)"
        class="animate-pop group relative block overflow-hidden rounded-card border border-line bg-surface p-5 shadow-card transition hover:-translate-y-1"
        :style="{ animationDelay: `${index * 40}ms` }">
        <span class="absolute inset-x-0 top-0 h-2" :class="stripes[index % stripes.length]"></span>
        <div class="flex items-start justify-between gap-2">
            <h3 class="text-xl leading-tight font-bold group-hover:underline">{{ board.title }}</h3>
            <span class="shrink-0 rounded-lg bg-surface2 px-2 py-1 font-mono text-xs font-bold tracking-widest">{{ board.code }}</span>
        </div>
        <p class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted">
            <span>{{ visLabel[board.visibility] }}{{ board.class_name ? ' ' + board.class_name : '' }}</span>
            <span>🗒️ {{ board.posts_count }} kartu</span>
            <span v-if="board.owner">👤 {{ board.owner }}</span>
        </p>
        <p class="mt-1 text-xs text-muted">Diperbarui {{ timeAgo(board.updated_at) }}</p>
    </Link>
</template>
