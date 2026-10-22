<script setup>
import { useId } from 'vue';

defineProps({
    label: String,
    error: String,
    hint: String,
    type: { type: String, default: 'text' },
    as: { type: String, default: 'input' }, // input | textarea | select
    rows: { type: Number, default: 4 },
});
const model = defineModel();
const id = useId();
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-bold text-ink">{{ label }}</label>
        <textarea v-if="as === 'textarea'" :id="id" v-model="model" :rows="rows" v-bind="$attrs"
            class="w-full rounded-xl border-2 border-line bg-surface px-4 py-3 text-ink placeholder:text-muted/70 focus:border-accent focus:outline-none" />
        <select v-else-if="as === 'select'" :id="id" v-model="model" v-bind="$attrs"
            class="h-12 w-full rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none">
            <slot />
        </select>
        <input v-else :id="id" v-model="model" :type="type" v-bind="$attrs"
            class="h-12 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink placeholder:text-muted/70 focus:border-accent focus:outline-none" />
        <p v-if="error" class="text-sm font-semibold text-danger">{{ error }}</p>
        <p v-else-if="hint" class="text-sm text-muted">{{ hint }}</p>
    </div>
</template>

<script>
export default { inheritAttrs: false };
</script>
