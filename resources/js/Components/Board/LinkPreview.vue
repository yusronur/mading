<script setup>
import { ref } from 'vue';

// Kartu pratinjau tautan; video YouTube dan sematan lain baru dimuat saat diketuk (hemat kuota).
const props = defineProps({ link: Object, compact: Boolean });
const playing = ref(false);
const icon = (p) => ({ Vimeo: '🎬', Canva: '🎨', 'Google Formulir': '📝', 'Google Slides': '📽️', 'Google Spreadsheet': '📊' })[p] ?? '📄';
const host = (u) => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } };
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-line bg-surface2/70">
        <template v-if="link.type === 'youtube' && link.youtube_id">
            <div class="relative aspect-video w-full bg-black">
                <iframe v-if="playing && !compact" class="absolute inset-0 size-full" :src="`https://www.youtube-nocookie.com/embed/${link.youtube_id}?autoplay=1&rel=0`"
                    title="Video YouTube" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                <button v-else type="button" class="group absolute inset-0 grid place-items-center" :aria-label="`Putar ${link.title}`" @click="playing = true">
                    <img v-if="link.image_url || link.image" :src="link.image_url || link.image" alt="" class="absolute inset-0 size-full object-cover" loading="lazy" />
                    <span class="relative grid h-12 w-16 place-items-center rounded-2xl bg-red-600 text-2xl text-white shadow-lg transition group-hover:scale-110">▶</span>
                </button>
            </div>
            <a :href="link.url" target="_blank" rel="noopener noreferrer nofollow" class="block px-3 py-2">
                <p class="line-clamp-2 text-sm font-bold">{{ link.title }}</p>
                <p class="text-xs text-muted">youtube.com</p>
            </a>
        </template>
        <template v-else-if="link.type === 'embed' && link.embed_url">
            <div class="relative w-full bg-surface" :style="{ aspectRatio: link.ratio || '16/9' }">
                <iframe v-if="playing && !compact" class="absolute inset-0 size-full" :src="link.embed_url" :title="link.title || link.provider"
                    allow="autoplay; fullscreen; picture-in-picture; clipboard-write" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"></iframe>
                <button v-else type="button" class="group absolute inset-0 grid place-items-center bg-surface2" :aria-label="`Buka ${link.title || link.provider}`" data-testid="embed-open" @click="playing = true">
                    <img v-if="link.image_url || link.image" :src="link.image_url || link.image" alt="" class="absolute inset-0 size-full object-cover opacity-80" loading="lazy" />
                    <span class="relative rounded-2xl bg-black/70 px-4 py-2 text-sm font-bold text-white shadow-lg transition group-hover:scale-105">{{ icon(link.provider) }} Buka {{ link.provider }}</span>
                </button>
            </div>
            <a :href="link.url" target="_blank" rel="noopener noreferrer nofollow" class="block px-3 py-2">
                <p class="line-clamp-2 text-sm font-bold">{{ link.title || link.provider }}</p>
                <p class="text-xs text-muted">{{ link.provider }} · buka di tab baru ↗</p>
            </a>
        </template>
        <a v-else :href="link.url" target="_blank" rel="noopener noreferrer nofollow" class="flex gap-3 p-2 hover:bg-surface2">
            <img v-if="link.image_url || link.image" :src="link.image_url || link.image" alt="" class="size-16 shrink-0 rounded-lg object-cover" loading="lazy" />
            <span v-else class="grid size-16 shrink-0 place-items-center rounded-lg bg-surface text-2xl">🔗</span>
            <span class="min-w-0 py-0.5">
                <span class="line-clamp-2 text-sm font-bold">{{ link.title || link.url }}</span>
                <span v-if="link.description && !compact" class="line-clamp-2 text-xs text-muted">{{ link.description }}</span>
                <span class="block truncate text-xs text-muted">{{ link.site || host(link.url) }}</span>
            </span>
        </a>
    </div>
</template>
