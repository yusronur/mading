<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Btn from '@/Components/Btn.vue';
import { confirmState as s, answerConfirm } from '@/lib/confirm';

const okBtn = ref(null);

const onKey = (e) => {
    if (e.key === 'Escape') answerConfirm(false);
};

watch(() => s.open, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        await nextTick();
        okBtn.value?.$el?.focus?.();
    } else {
        document.removeEventListener('keydown', onKey);
    }
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="s.open" class="fixed inset-0 z-[55] flex items-end justify-center bg-black/60 p-4 sm:items-center" @click.self="answerConfirm(false)">
                <div role="alertdialog" aria-modal="true" :aria-label="s.title"
                    class="animate-pop w-full max-w-sm rounded-[var(--m-radius)] border border-line bg-surface p-6 shadow-card">
                    <h2 class="text-xl font-bold">{{ s.title }}</h2>
                    <p v-if="s.message" class="mt-2 whitespace-pre-line text-muted">{{ s.message }}</p>
                    <div class="mt-6 flex justify-end gap-2">
                        <Btn variant="ghost" @click="answerConfirm(false)">{{ s.cancelText }}</Btn>
                        <Btn ref="okBtn" :variant="s.tone === 'danger' ? 'danger' : 'primary'" @click="answerConfirm(true)">{{ s.confirmText }}</Btn>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
