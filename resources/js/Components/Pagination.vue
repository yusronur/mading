<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ links: Array });
</script>

<template>
    <nav v-if="links && links.length > 3" class="flex flex-wrap gap-1.5" aria-label="Halaman">
        <template v-for="(l, i) in links" :key="i">
            <Link v-if="l.url" :href="l.url" preserve-scroll
                class="grid h-9 min-w-9 place-items-center rounded-lg px-3 text-sm font-bold"
                :class="l.active ? 'bg-accent text-on-accent' : 'bg-surface2 text-ink hover:bg-line'"
                v-html="l.label" />
            <span v-else class="grid h-9 min-w-9 place-items-center px-2 text-sm text-muted" v-html="l.label" />
        </template>
    </nav>
</template>
