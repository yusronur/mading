<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import { upload } from '@/lib/http';
import { toast } from '@/lib/toast';

// Impor soal Excel/Word dengan pratinjau (QZ-07).
const props = defineProps({ show: Boolean, bank: Object });
const emit = defineEmits(['close']);
const file = ref(null);
const preview = ref(null);
const busy = ref(false);
const progress = ref(0);

watch(() => props.show, (v) => v && ((file.value = null), (preview.value = null)));

const pick = async (e) => {
    file.value = e.target.files[0] || null;
    e.target.value = '';
    preview.value = null;
    if (!file.value) return;
    busy.value = true;
    try {
        preview.value = await upload(route('banks.import.preview', props.bank.id), file.value, (p) => (progress.value = p));
    } catch (err) {
        toast(err.message, 'error');
        file.value = null;
    } finally {
        busy.value = false;
    }
};

const commit = () => {
    busy.value = true;
    router.post(route('banks.import', props.bank.id), { file: file.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => (busy.value = false),
    });
};
</script>

<template>
    <Modal :show="show" :title="`Impor soal ke ${bank?.name ?? ''}`" wide @close="emit('close')">
        <div class="space-y-4">
            <div class="grid gap-2 sm:grid-cols-2">
                <a :href="route('banks.template', 'xlsx')" class="flex items-center gap-3 rounded-xl bg-surface2 p-3 hover:bg-line/60">
                    <span class="text-2xl">📊</span><span><b>Templat Excel</b><br /><span class="text-sm text-muted">Satu baris satu soal</span></span>
                </a>
                <a :href="route('banks.template', 'docx')" class="flex items-center gap-3 rounded-xl bg-surface2 p-3 hover:bg-line/60">
                    <span class="text-2xl">📝</span><span><b>Templat Word</b><br /><span class="text-sm text-muted">Gambar di soal ikut terbaca</span></span>
                </a>
            </div>

            <label class="flex cursor-pointer flex-col items-center gap-1 rounded-2xl border-2 border-dashed border-line p-6 text-center hover:border-accent">
                <span class="text-3xl">📥</span>
                <span class="font-bold">{{ file ? file.name : 'Pilih berkas .xlsx atau .docx' }}</span>
                <span class="text-sm text-muted">{{ busy && !preview ? `Membaca… ${progress}%` : 'Soal dicek dulu sebelum disimpan' }}</span>
                <input type="file" class="sr-only" accept=".xlsx,.xls,.docx,.csv" @change="pick" />
            </label>

            <template v-if="preview">
                <p class="font-bold">{{ preview.valid }} dari {{ preview.total }} soal siap diimpor<span v-if="preview.total - preview.valid" class="text-danger"> · {{ preview.total - preview.valid }} bermasalah (dilewati)</span></p>
                <div class="max-h-80 overflow-y-auto rounded-xl border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-surface2 text-xs text-muted uppercase"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">Tipe</th><th class="px-3 py-2">Soal</th><th class="px-3 py-2">Kunci</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="r in preview.rows" :key="r.line" :class="r.errors.length ? 'bg-danger/10' : ''">
                                <td class="px-3 py-2 text-muted">{{ r.line }}</td>
                                <td class="px-3 py-2"><Badge :tone="r.errors.length ? 'danger' : 'neutral'">{{ r.type_label }}</Badge></td>
                                <td class="px-3 py-2">
                                    {{ r.prompt }} <span v-if="r.images" class="text-muted">🖼️{{ r.images }}</span>
                                    <p v-for="e in r.errors" :key="e" class="font-semibold text-danger">⚠ {{ e }}</p>
                                </td>
                                <td class="px-3 py-2 font-mono">{{ r.key }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Btn block size="lg" :loading="busy" :disabled="!preview.valid" @click="commit">Impor {{ preview.valid }} soal</Btn>
            </template>
        </div>
    </Modal>
</template>
