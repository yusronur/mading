<script setup>
// Bentuk jawaban orisinal (bintang, bulan, petir, hati, daun, tetes) agar pilihan
// bisa dikenali tanpa bergantung pada warna saja.
defineProps({ index: { type: Number, required: true }, size: { type: [Number, String], default: 28 } });
const PATHS = [
    'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z',
    'M15.5 3.2a9 9 0 1 0 5.3 12.6A7.5 7.5 0 0 1 15.5 3.2z',
    'M13.5 2 4.5 13.5h6L9.5 22l9-11.5h-6z',
    'M12 21s-8-5.2-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.8-8 11-8 11z',
    'M20 4C9 4 4 9.5 4 16.5c0 1.3.2 2.4.5 3.5C6 13 10.5 9.5 16 8c-4.5 2.5-8 6-9.5 12 1 .3 2 .5 3 .5C17 20.5 20 13 20 4z',
    'M12 2.5S5 10.2 5 14.5a7 7 0 0 0 14 0C19 10.2 12 2.5 12 2.5z',
];
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" aria-hidden="true" class="shrink-0"><path :d="PATHS[index % PATHS.length]" fill="currentColor" /></svg>
</template>
