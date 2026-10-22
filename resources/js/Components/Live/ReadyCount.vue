<script setup>
// Hitung mundur sebelum soal tampil. Dipakai sama persis di layar guru dan siswa,
// supaya soal baru terlihat bersamaan (jam diselaraskan dengan server).
defineProps({ n: { type: Number, required: true }, big: { type: Boolean, default: false } });
</script>

<template>
    <div class="m-auto flex flex-col items-center justify-center text-center" role="status" aria-live="polite" data-testid="ready-count">
        <p class="font-bold text-muted" :class="big ? 'text-3xl' : 'text-xl'">Bersiap…</p>
        <p :key="n" class="ready-pop font-display leading-none font-black text-accent" :class="big ? 'text-[14rem]' : 'text-[9rem]'">{{ n }}</p>
    </div>
</template>

<style scoped>
.ready-pop { animation: pop 0.9s cubic-bezier(.2, .8, .2, 1) both; }
@keyframes pop { 0% { transform: scale(.4); opacity: 0; } 25% { transform: scale(1.12); opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .ready-pop { animation: none; } }
</style>
