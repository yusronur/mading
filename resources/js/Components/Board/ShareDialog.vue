<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import { copyText } from '@/lib/clipboard';
import { toast } from '@/lib/toast';

// Bagikan papan: kode, tautan, QR, ekspor, salin papan (SHR-01, SHR-04, SHR-05, BRD-14).
const props = defineProps({ show: Boolean, board: Object, can: Object, secretUrl: String });
const emit = defineEmits(['close', 'png']);
const qr = ref('');

watch(() => props.show, async (open) => {
    if (open) qr.value = await QRCode.toDataURL(props.secretUrl || props.board.join_url, { margin: 1, width: 360, errorCorrectionLevel: 'M' });
});

const copy = async (text, label) => toast((await copyText(text)) ? `${label} tersalin` : 'Gagal menyalin', 'success');
const downloadQr = () => {
    const a = document.createElement('a');
    a.href = qr.value;
    a.download = `qr-${props.board.code}.png`;
    a.click();
};
const duplicate = (withPosts) => router.post(route('boards.duplicate', props.board.id), { with_posts: withPosts }, { onSuccess: () => emit('close') });
</script>

<template>
    <Modal :show="show" title="Bagikan & ekspor" @close="emit('close')">
        <div class="space-y-5">
            <div class="flex flex-col items-center gap-3 rounded-2xl bg-white p-4 text-center text-slate-900">
                <img v-if="qr" :src="qr" alt="Kode QR untuk bergabung ke papan" class="size-52" />
                <p class="text-sm font-semibold">Pindai untuk membuka papan</p>
                <p class="font-mono text-3xl font-bold tracking-[0.25em]">{{ board.code }}</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <Btn variant="soft" @click="copy(secretUrl || board.join_url, 'Tautan')">🔗 Salin tautan</Btn>
                <Btn variant="soft" @click="copy(board.code, 'Kode')">🔢 Salin kode</Btn>
                <Btn variant="soft" @click="downloadQr">⬇️ Unduh QR</Btn>
                <a :href="route('boards.print', board.id)" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center gap-2 rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-5 font-display font-semibold hover:bg-line/60">🖨️ Cetak / PDF</a>
                <Btn variant="soft" @click="emit('png')">🖼️ Simpan gambar PNG</Btn>
                <template v-if="can.manage">
                    <a :href="route('boards.export', [board.id, 'xlsx'])" class="inline-flex h-11 items-center justify-center gap-2 rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-5 font-display font-semibold hover:bg-line/60">📊 Excel</a>
                    <a :href="route('boards.export', [board.id, 'csv'])" class="inline-flex h-11 items-center justify-center gap-2 rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-5 font-display font-semibold hover:bg-line/60">📄 CSV</a>
                </template>
            </div>
            <p class="text-xs text-muted">Tautan dan QR tetap meminta siswa masuk dengan akun sekolah. Ekspor Excel berisi nama penulis asli, termasuk kartu anonim.</p>
            <div v-if="can.manage" class="border-t border-line pt-4">
                <p class="mb-2 font-bold">Salin papan (remake)</p>
                <div class="flex flex-wrap gap-2">
                    <Btn size="sm" variant="soft" @click="duplicate(false)">Hanya struktur & pengaturan</Btn>
                    <Btn size="sm" variant="soft" @click="duplicate(true)">Beserta semua kartu</Btn>
                </div>
            </div>
        </div>
    </Modal>
</template>
