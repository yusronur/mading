<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Mascot from '@/Components/Mascot.vue';

const props = defineProps({ status: Number });
const info = computed(() => ({
    403: ['Ups, bukan untukmu', 'Kamu tidak punya akses ke halaman ini.'],
    404: ['Halaman tidak ditemukan', 'Mungkin tautannya salah atau papan sudah dihapus.'],
    500: ['Ada yang rusak', 'Server sedang bermasalah. Coba lagi sebentar lagi.'],
    503: ['Sedang perawatan', 'Mading sedang diperbarui. Kembali beberapa menit lagi ya.'],
}[props.status] ?? ['Terjadi kesalahan', '']));
</script>

<template>
    <Head :title="info[0]" />
    <div class="grid min-h-dvh place-items-center px-6 text-center">
        <div class="flex flex-col items-center gap-3">
            <Mascot mood="sad" :size="120" />
            <p class="font-display text-6xl font-bold text-accent">{{ status }}</p>
            <h1 class="text-3xl font-bold">{{ info[0] }}</h1>
            <p class="max-w-sm text-muted">{{ info[1] }}</p>
            <Link href="/" class="mt-2 rounded-xl bg-accent px-5 py-3 font-display font-semibold text-on-accent">Kembali ke beranda</Link>
        </div>
    </div>
</template>
