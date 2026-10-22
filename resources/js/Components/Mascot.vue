<script setup>
// Maskot orisinal sementara ("Dodo si gumpalan"). Ganti dengan maskot sekolah di Fase 2.
defineProps({ mood: { type: String, default: 'happy' }, size: { type: Number, default: 96 } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 120 120" role="img" aria-label="Maskot">
        <defs>
            <linearGradient id="mascot-g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="var(--m-accent)" />
                <stop offset="1" stop-color="var(--m-accent3)" />
            </linearGradient>
        </defs>
        <path d="M60 10c26 0 46 18 48 44 2 28-18 54-48 54S10 84 12 56C14 28 34 10 60 10z" fill="url(#mascot-g)" />
        <ellipse cx="44" cy="54" rx="8" ry="10" fill="var(--m-bg)" />
        <ellipse cx="76" cy="54" rx="8" ry="10" fill="var(--m-bg)" />
        <circle cx="46.5" cy="50.5" r="3" fill="#fff" />
        <circle cx="78.5" cy="50.5" r="3" fill="#fff" />
        <path v-if="mood === 'happy'" d="M46 74c8 9 20 9 28 0" stroke="var(--m-bg)" stroke-width="5" stroke-linecap="round" fill="none" />
        <path v-else-if="mood === 'sad'" d="M47 82c8-8 18-8 26 0" stroke="var(--m-bg)" stroke-width="5" stroke-linecap="round" fill="none" />
        <circle v-else cx="60" cy="78" r="6" fill="var(--m-bg)" />
        <ellipse cx="32" cy="68" rx="6" ry="4" fill="var(--m-accent2)" opacity=".55" />
        <ellipse cx="88" cy="68" rx="6" ry="4" fill="var(--m-accent2)" opacity=".55" />
    </svg>
</template>
