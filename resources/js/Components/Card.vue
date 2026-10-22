<script setup>
defineProps({ padded: { type: Boolean, default: true } });
</script>

<template>
    <div class="rounded-card border border-line bg-surface shadow-card" :class="padded ? 'p-5 sm:p-6' : ''">
        <slot />
    </div>
</template>
