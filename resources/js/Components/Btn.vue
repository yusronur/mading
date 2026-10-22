<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: String,
    variant: { type: String, default: 'primary' }, // primary | soft | ghost | danger
    size: { type: String, default: 'md' }, // sm | md | lg
    type: { type: String, default: 'button' },
    disabled: Boolean,
    loading: Boolean,
    block: Boolean,
    method: String,
    as: String,
});

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 font-display font-semibold select-none transition active:scale-[0.97] disabled:opacity-50 disabled:pointer-events-none',
    'rounded-[calc(var(--m-radius)*0.7)]',
    {
        sm: 'h-9 px-3 text-sm',
        md: 'h-11 px-5 text-base',
        lg: 'h-14 px-7 text-lg',
    }[props.size],
    {
        primary: 'bg-accent text-on-accent shadow-card hover:brightness-110',
        soft: 'bg-surface2 text-ink hover:bg-line/60',
        ghost: 'text-ink hover:bg-surface2',
        danger: 'bg-danger/15 text-danger hover:bg-danger/25',
    }[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <Link v-if="href" :href="href" :method="method" :as="as" :class="classes"><slot /></Link>
    <button v-else :type="type" :disabled="disabled || loading" :class="classes">
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
