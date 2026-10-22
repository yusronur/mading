<script setup>
import { computed } from 'vue';

// Awan kata: ukuran kata mengikuti banyaknya yang menjawab (LIV-01).
const props = defineProps({ words: Array, big: Boolean, removable: Boolean });
const emit = defineEmits(['remove']);

const COLORS = ['#ff6b81', '#2f6fed', '#ffbe2e', '#26c6a8', '#7c4ddb', '#ff8f3d'];
const hash = (s) => { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) | 0; return Math.abs(h); };

const max = computed(() => Math.max(1, ...(props.words ?? []).map((w) => w.count)));
const items = computed(() => [...(props.words ?? [])]
    .sort((a, b) => hash(a.text) - hash(b.text))   // letak acak yang stabil
    .map((w) => ({
        ...w,
        size: (props.big ? 26 : 15) + (props.big ? 74 : 38) * Math.pow(w.count / max.value, 0.7),
        color: COLORS[hash(w.text) % COLORS.length],
    })));
</script>

<template>
    <div class="flex flex-wrap content-center items-center justify-center gap-x-5 gap-y-1 py-3" :class="big ? 'min-h-[50vh]' : 'min-h-40'" data-testid="word-cloud">
        <p v-if="!items.length" class="font-semibold text-muted" :class="big ? 'text-3xl' : ''">Belum ada kata. Jadilah yang pertama!</p>
        <span v-for="w in items" :key="w.text" class="group relative inline-block font-display leading-tight font-extrabold transition-[font-size] duration-500"
            :style="{ fontSize: w.size + 'px', color: w.color }" :title="`${w.text} (${w.count})`">
            <span class="rounded-lg px-1 transition-colors" :class="removable ? 'group-hover:bg-surface2' : ''">{{ w.text }}<sup v-if="w.count > 1" class="ml-0.5 text-[0.4em] font-bold text-muted">{{ w.count }}</sup></span>
            <!-- Hapus hanya lewat tombol ✕ di pojok kata, bukan klik pada kata -->
            <button v-if="removable" type="button" :aria-label="`Hapus kata ${w.text}`" title="Hapus kata ini"
                class="absolute -top-2 -right-3 grid size-5 place-items-center rounded-full bg-danger text-[11px] leading-none font-bold text-bg opacity-0 transition group-focus-within:opacity-100 group-hover:opacity-100 hover:scale-110 focus-visible:opacity-100"
                :data-testid="`cloud-remove-${w.text}`" @click.stop="emit('remove', w.text)">✕</button>
        </span>
    </div>
</template>
