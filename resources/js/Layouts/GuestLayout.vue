<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Flash from '@/Components/Flash.vue';
import { applyTheme } from '@/lib/theme';

const page = usePage();
watch(() => page.props.theme, (t) => applyTheme(t), { immediate: true, deep: true });
</script>

<template>
    <div class="relative grid min-h-dvh place-items-center overflow-hidden px-4 py-10">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
            <div class="animate-blob absolute -top-20 -left-16 size-80 rounded-full bg-accent/25 blur-3xl"></div>
            <div class="animate-blob absolute -right-24 bottom-0 size-96 rounded-full bg-accent2/25 blur-3xl" style="animation-delay:-3s"></div>
            <div class="animate-blob absolute top-1/3 left-1/2 size-64 rounded-full bg-accent3/20 blur-3xl" style="animation-delay:-6s"></div>
        </div>
        <Flash />
        <div class="w-full max-w-md">
            <slot />
            <p class="mt-8 text-center text-sm text-muted">Didukung <span class="font-bold text-ink">{{ $page.props.app?.brand }}</span></p>
        </div>
    </div>
</template>
