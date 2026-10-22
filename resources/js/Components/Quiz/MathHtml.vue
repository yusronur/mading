<script setup>
import { computed } from 'vue';
import { mathHtml, mathText } from '@/lib/math';

// Menampilkan HTML aman (atau teks polos bila `plain`) dengan rumus KaTeX.
const props = defineProps({ html: String, plain: Boolean, tag: { type: String, default: 'div' } });
const out = computed(() => (props.plain ? mathText(props.html) : mathHtml(props.html)));
</script>

<template>
    <component :is="tag" class="math-html" v-html="out" />
</template>
