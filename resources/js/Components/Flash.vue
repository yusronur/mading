<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toasts, toast, dismissToast } from '@/lib/toast';

// Pesan dari server (flash) ditampilkan lewat sistem toast yang sama
// dengan pemberitahuan dari sisi klien (mis. "Kode tersalin").
const page = usePage();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.error) toast(flash.error, 'error');
        else if (flash?.success) toast(flash.success, 'success');
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-4 top-4 z-[60] mx-auto flex max-w-md flex-col gap-2 sm:top-6" aria-live="polite">
        <TransitionGroup enter-from-class="opacity-0 -translate-y-3" leave-to-class="opacity-0 -translate-y-3"
            enter-active-class="transition duration-200" leave-active-class="transition duration-200 absolute inset-x-0">
            <div v-for="t in toasts" :key="t.id" role="status"
                class="pointer-events-auto flex items-start gap-3 rounded-2xl px-4 py-3 font-semibold shadow-card"
                :class="t.tone === 'error' ? 'bg-danger text-bg' : 'bg-accent text-on-accent'">
                <span aria-hidden="true">{{ t.tone === 'error' ? '⚠️' : '✅' }}</span>
                <span class="flex-1 break-words">{{ t.text }}</span>
                <button v-if="t.action" type="button" class="shrink-0 rounded-lg bg-white/25 px-2.5 py-0.5 font-bold underline-offset-2 hover:bg-white/40"
                    @click="t.action.run(); dismissToast(t.id)">{{ t.action.label }}</button>
                <button class="opacity-70 hover:opacity-100" aria-label="Tutup" @click="dismissToast(t.id)">✕</button>
            </div>
        </TransitionGroup>
    </div>
</template>
