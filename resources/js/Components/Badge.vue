<script setup>
defineProps({ tone: { type: String, default: 'neutral' } }); // neutral | accent | success | warning | danger
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
        :class="{
            neutral: 'bg-surface2 text-muted',
            accent: 'bg-accent/15 text-ink ring-1 ring-accent/50',
            success: 'bg-success/15 text-success',
            warning: 'bg-warning/15 text-warning',
            danger: 'bg-danger/15 text-danger',
        }[tone]">
        <slot />
    </span>
</template>
