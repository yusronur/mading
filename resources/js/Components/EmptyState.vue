<script setup>
import Mascot from './Mascot.vue';
defineProps({ title: String, text: String, mood: { type: String, default: 'wow' } });
</script>

<template>
    <div class="flex flex-col items-center gap-3 rounded-card border-2 border-dashed border-line px-6 py-10 text-center">
        <Mascot :mood="mood" :size="84" />
        <h3 class="text-xl font-bold">{{ title }}</h3>
        <p v-if="text" class="max-w-sm text-muted">{{ text }}</p>
        <slot />
    </div>
</template>
