<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';

// persistent: klik di luar kotak TIDAK menutup (hanya menggetarkan kotak sebagai isyarat),
// untuk formulir panjang yang tidak boleh hilang karena salah ketuk. Tombol ✕ dan Esc
// tetap memanggil 'close'; pemilik formulir yang memutuskan (mis. konfirmasi bila ada isian).
const props = defineProps({ show: Boolean, title: String, wide: Boolean, persistent: Boolean });
const emit = defineEmits(['close']);

const nudge = ref(false);
const onBackdrop = () => {
    if (!props.persistent) return emit('close');
    nudge.value = true;
    setTimeout(() => (nudge.value = false), 400);
};
const onKey = (e) => e.key === 'Escape' && emit('close');
watch(() => props.show, (v) => {
    if (v) document.addEventListener('keydown', onKey);
    else document.removeEventListener('keydown', onKey);
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="show" class="fixed inset-0 z-40 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-6" @click.self="onBackdrop">
                <div role="dialog" aria-modal="true" :aria-label="title"
                    class="max-h-[92dvh] w-full overflow-y-auto rounded-t-[var(--m-radius)] border border-line bg-surface p-6 shadow-card sm:rounded-[var(--m-radius)]" :class="[wide ? 'sm:max-w-3xl' : 'sm:max-w-lg', nudge ? 'animate-nudge' : 'animate-pop']">
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <h2 class="text-2xl font-bold">{{ title }}</h2>
                        <button class="grid size-9 place-items-center rounded-full bg-surface2 text-muted hover:text-ink" aria-label="Tutup" @click="emit('close')">✕</button>
                    </div>
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
