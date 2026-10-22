<script setup>
import { onMounted, reactive } from 'vue';
import { Head } from '@inertiajs/vue3';
import QRCode from 'qrcode';

const props = defineProps({ cards: Array, missing: Number, loginUrl: String, schoolName: String, title: String });
const qrs = reactive({});
const print = () => window.print();

onMounted(async () => {
    for (const c of props.cards) {
        if (c.qr_url) qrs[c.id] = await QRCode.toDataURL(c.qr_url, { margin: 1, width: 260, errorCorrectionLevel: 'M' });
    }
});
</script>

<template>
    <Head :title="'Kartu akun' + (title ? ' ' + title : '')" />
    <div class="min-h-dvh bg-white p-4 text-black print:p-0">
        <!-- Bilah alat: tidak ikut tercetak -->
        <div class="mx-auto mb-4 flex max-w-[210mm] flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-100 px-4 py-3 print:hidden">
            <div>
                <p class="font-bold">{{ cards.length }} kartu akun{{ title ? ' · ' + title : '' }}</p>
                <p v-if="missing" class="text-sm font-semibold text-red-700">{{ missing }} siswa belum punya kode QR maupun sandi awal dan tidak ikut dicetak.</p>
                <p v-else class="text-sm text-slate-600">Potong sepanjang garis putus-putus, lalu bagikan ke masing-masing siswa. Simpan kartu seperti kunci rumah.</p>
            </div>
            <button class="h-11 rounded-xl bg-slate-900 px-6 font-bold text-white" @click="print">🖨️ Cetak</button>
        </div>

        <p v-if="!cards.length" class="mx-auto max-w-[210mm] py-16 text-center text-slate-600 print:hidden">Belum ada kartu untuk dicetak.</p>

        <div class="mx-auto grid max-w-[210mm] grid-cols-1 sm:grid-cols-2 print:grid-cols-2">
            <article v-for="c in cards" :key="c.id" class="flex break-inside-avoid gap-3 border border-dashed border-slate-400 p-4" style="min-height: 54mm">
                <div v-if="c.qr_url" class="flex w-[30mm] shrink-0 flex-col items-center">
                    <img v-if="qrs[c.id]" :src="qrs[c.id]" :alt="'QR login ' + c.name" class="w-[30mm]" />
                    <div v-else class="size-[30mm] bg-slate-100"></div>
                    <p class="mt-1 text-center text-[9px] leading-tight font-bold text-slate-600">PINDAI UNTUK MASUK</p>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="truncate text-[10px] font-bold tracking-wide text-slate-600 uppercase">{{ schoolName }}</p>
                        <p v-if="c.class" class="shrink-0 rounded bg-slate-900 px-2 py-0.5 text-xs font-bold text-white">{{ c.class }}</p>
                        <p v-else class="text-xs font-bold text-slate-600">{{ c.role_label }}</p>
                    </div>
                    <p class="mt-1.5 text-base leading-tight font-bold">{{ c.name }}</p>
                    <dl class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-sm">
                        <dt class="text-slate-600">Username</dt>
                        <dd class="font-mono font-bold">{{ c.username }}</dd>
                        <template v-if="c.password">
                            <dt class="text-slate-600">Sandi awal</dt>
                            <dd class="font-mono text-lg font-bold tracking-[0.12em]">{{ c.password }}</dd>
                        </template>
                    </dl>
                    <p class="mt-1.5 text-[10px] leading-snug text-slate-700">
                        <template v-if="c.qr_url">Pindai QR dengan kamera HP untuk langsung masuk. </template>
                        <template v-if="c.password">Atau buka <b>{{ loginUrl }}</b>; sandi awal wajib diganti saat masuk pertama. </template>
                        Jangan pinjamkan kartu ini.
                    </p>
                </div>
            </article>
        </div>
    </div>
</template>
