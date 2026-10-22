<script setup>
import { onBeforeUnmount, watch } from 'vue';

// Penampil layar penuh untuk gambar, PDF, audio, dan video lampiran.
const props = defineProps({ item: Object });
const emit = defineEmits(['close']);
const onKey = (e) => e.key === 'Escape' && emit('close');
watch(() => props.item, (v) => (v ? document.addEventListener('keydown', onKey) : document.removeEventListener('keydown', onKey)));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="item" class="fixed inset-0 z-50 flex flex-col bg-black/90" role="dialog" aria-modal="true" :aria-label="item.name" @click.self="emit('close')">
            <div class="flex items-center gap-3 p-3 text-white">
                <p class="min-w-0 flex-1 truncate font-bold">{{ item.name }}</p>
                <a :href="item.download_url" class="rounded-lg bg-white/15 px-3 py-1.5 text-sm font-bold hover:bg-white/25">⬇️ Unduh</a>
                <button type="button" class="grid size-10 place-items-center rounded-full bg-white/15 text-xl hover:bg-white/25" aria-label="Tutup" @click="emit('close')">✕</button>
            </div>
            <div class="flex min-h-0 flex-1 items-center justify-center p-3 pt-0" @click.self="emit('close')">
                <img v-if="item.kind === 'image'" :src="item.url" :alt="item.name" class="max-h-full max-w-full rounded-lg object-contain" />
                <video v-else-if="item.kind === 'video'" :src="item.url" controls autoplay playsinline class="max-h-full max-w-full rounded-lg"></video>
                <audio v-else-if="item.kind === 'audio'" :src="item.url" controls autoplay class="w-full max-w-lg"></audio>
                <iframe v-else-if="item.mime === 'application/pdf'" :src="item.url" :title="item.name" class="size-full max-w-5xl rounded-lg bg-white"></iframe>
            </div>
        </div>
    </Teleport>
</template>
