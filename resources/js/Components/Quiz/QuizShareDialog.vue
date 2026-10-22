<script setup>
import { ref, watch } from 'vue';
import QRCode from 'qrcode';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import { copyText } from '@/lib/clipboard';
import { toast } from '@/lib/toast';

// Bagikan kuis: siswa cukup memasukkan kode di beranda, atau memindai QR (QZ-09, SHR-02).
const props = defineProps({ show: Boolean, session: Object });
const emit = defineEmits(['close']);
const qr = ref('');

watch(() => props.show, async (open) => {
    if (open && props.session) qr.value = await QRCode.toDataURL(props.session.join_url, { margin: 1, width: 360, errorCorrectionLevel: 'M' });
});

const copy = async (text, label) => {
    const ok = await copyText(text);
    toast(ok ? `${label} tersalin` : 'Gagal menyalin. Salin secara manual.', ok ? 'success' : 'error');
};
const downloadQr = () => {
    const a = document.createElement('a');
    a.href = qr.value;
    a.download = `qr-kuis-${props.session.code}.png`;
    a.click();
};
</script>

<template>
    <Modal :show="show" title="Bagikan kuis" @close="emit('close')">
        <div v-if="session" class="space-y-4">
            <div class="flex flex-col items-center gap-3 rounded-2xl bg-white p-4 text-center text-slate-900">
                <img v-if="qr" :src="qr" alt="Kode QR untuk membuka kuis" class="size-52" />
                <p class="text-sm font-semibold">Pindai, atau ketik kode ini di beranda siswa</p>
                <p class="font-mono text-3xl font-bold tracking-[0.25em]">{{ session.code }}</p>
                <p class="text-xs text-slate-600">{{ session.title }}<span v-if="session.class_name"> · {{ session.class_name }}</span></p>
            </div>
            <div class="grid gap-2 sm:grid-cols-3">
                <Btn variant="soft" @click="copy(session.code, 'Kode')">🔢 Salin kode</Btn>
                <Btn variant="soft" @click="copy(session.join_url, 'Tautan')">🔗 Salin tautan</Btn>
                <Btn variant="soft" @click="downloadQr">⬇️ Unduh QR</Btn>
            </div>
            <p class="text-xs text-muted">Hanya siswa di rombel yang ditugaskan yang bisa membuka kuis ini. Siswa tetap harus masuk dengan akunnya.</p>
        </div>
    </Modal>
</template>
