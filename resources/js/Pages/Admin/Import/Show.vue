<script setup>
import { computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import { askConfirm } from '@/lib/confirm';

const props = defineProps({
    import: Object, summary: Object, rows: Array, columns: Array, labels: Object, onlyProblems: Boolean, truncated: Boolean,
});
const imp = computed(() => props.import);
const typeLabel = { students: 'siswa', teachers: 'guru' };
const rowTone = { new: 'success', update: 'accent', error: 'danger', valid: 'neutral' };
const rowLabel = { new: 'Baru', update: 'Diperbarui', error: 'Bermasalah', valid: 'Valid' };

const processing = computed(() => imp.value.status === 'processing');
const confirmImport = async () => {
    const n = props.summary.new + props.summary.update;
    const ok = await askConfirm({
        title: `Proses ${n} baris?`,
        message: `Data ${typeLabel[imp.value.type]} akan dibuat atau diperbarui. Baris bermasalah dilewati.`,
        confirmText: 'Proses',
    });
    if (ok) router.post(route('admin.imports.process', imp.value.id), {}, { preserveScroll: true });
};
const cancel = () => router.delete(route('admin.imports.destroy', imp.value.id));
const forgetCredentials = async () => {
    const ok = await askConfirm({
        title: 'Hapus daftar sandi awal?',
        message: 'Berkas unduhan ini dihapus dari server. Kartu akun per rombel tetap bisa dicetak dari menu Kartu Akun selama siswa belum mengganti sandi.',
        confirmText: 'Hapus daftar',
        tone: 'danger',
    });
    if (ok) router.delete(route('admin.imports.destroy', imp.value.id), { preserveScroll: true });
};
const toggleProblems = () => router.get(route('admin.imports.show', imp.value.id), props.onlyProblems ? {} : { masalah: 1 }, { preserveScroll: true, preserveState: true });
const show = (v) => (Array.isArray(v) ? v.join('; ') : v || '–');
</script>

<template>
    <Head title="Pratinjau impor" />
    <AppLayout :title="imp.status === 'preview' ? 'Pratinjau impor' : 'Hasil impor'" wide :back="{ href: route('admin.imports.index'), label: 'Impor' }">

        <p class="-mt-3 mb-5 text-muted">Berkas <span class="font-bold text-ink">{{ imp.original_name }}</span> · data {{ typeLabel[imp.type] }} · {{ imp.total_rows }} baris</p>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Card><p class="font-display text-3xl font-bold text-success">{{ imp.status === 'done' ? imp.created_count : summary.new }}</p><p class="text-sm font-semibold text-muted">akun baru</p></Card>
            <Card><p class="font-display text-3xl font-bold">{{ imp.status === 'done' ? imp.updated_count : summary.update }}</p><p class="text-sm font-semibold text-muted">diperbarui</p></Card>
            <Card><p class="font-display text-3xl font-bold text-danger">{{ summary.error }}</p><p class="text-sm font-semibold text-muted">bermasalah (dilewati)</p></Card>
            <Card><p class="font-display text-3xl font-bold text-warning">{{ summary.warnings }}</p><p class="text-sm font-semibold text-muted">peringatan</p></Card>
        </div>

        <Card v-if="imp.status === 'preview'" class="mb-6 flex flex-wrap items-center justify-between gap-4 border-accent">
            <div>
                <p class="text-lg font-bold">Siap diproses?</p>
                <p class="text-muted">Akun baru mendapat sandi awal acak yang bisa diunduh setelah proses selesai.</p>
            </div>
            <div class="flex gap-2">
                <Btn variant="ghost" @click="cancel">Batalkan</Btn>
                <Btn size="lg" :disabled="summary.new + summary.update === 0" @click="confirmImport">Proses {{ summary.new + summary.update }} baris ✅</Btn>
            </div>
        </Card>

        <Card v-else-if="processing" class="mb-6 border-accent">
            <p class="text-lg font-bold">⏳ Sedang diproses di antrean…</p>
            <Btn class="mt-3" size="sm" variant="soft" @click="router.reload()">Muat ulang</Btn>
        </Card>

        <Card v-else-if="imp.status === 'failed'" class="mb-6 border-danger">
            <p class="text-lg font-bold text-danger">Impor gagal</p>
            <p class="text-muted">{{ imp.error_message }}</p>
        </Card>

        <Card v-else class="mb-6 flex flex-wrap items-center justify-between gap-4 border-success">
            <div>
                <p class="text-lg font-bold">🎉 Impor selesai</p>
                <p class="text-muted">{{ imp.created_count }} akun baru · {{ imp.updated_count }} diperbarui · {{ imp.skipped_count }} dilewati</p>
            </div>
            <div v-if="imp.has_credentials" class="flex flex-wrap gap-2">
                <a :href="route('admin.imports.credentials', imp.id)" class="inline-flex h-11 items-center rounded-xl bg-accent px-5 font-display font-semibold text-on-accent">⬇️ Unduh daftar akun & sandi awal</a>
                <Btn variant="danger" @click="forgetCredentials">Hapus dari server</Btn>
            </div>
            <Link v-else :href="route('admin.users.index', { role: imp.type === 'students' ? 'student' : 'teacher' })" class="font-bold underline">Lihat pengguna →</Link>
        </Card>

        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="text-xl font-bold">Rincian baris</h2>
            <Btn size="sm" variant="soft" @click="toggleProblems">{{ onlyProblems ? 'Tampilkan semua' : 'Hanya yang bermasalah' }}</Btn>
        </div>
        <Card :padded="false" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface2 text-xs uppercase text-muted">
                        <tr>
                            <th class="px-3 py-2.5">Baris</th>
                            <th class="px-3 py-2.5">Status</th>
                            <th v-for="c in columns" :key="c" class="px-3 py-2.5">{{ labels[c] }}</th>
                            <th class="px-3 py-2.5">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in rows" :key="r.line" :class="r.status === 'error' ? 'bg-danger/5' : ''">
                            <td class="px-3 py-2 text-muted">{{ r.line }}</td>
                            <td class="px-3 py-2"><Badge :tone="rowTone[r.status]">{{ rowLabel[r.status] }}</Badge></td>
                            <td v-for="c in columns" :key="c" class="px-3 py-2" :class="c === 'username' ? 'font-mono' : ''">{{ show(r[c]) }}</td>
                            <td class="px-3 py-2">
                                <p v-for="e in r.errors" :key="e" class="font-semibold text-danger">{{ e }}</p>
                                <p v-for="w in r.warnings || []" :key="w" class="text-warning">{{ w }}</p>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length + 3" class="px-3 py-8 text-center text-muted">Tidak ada baris untuk ditampilkan.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>
        <p v-if="truncated" class="mt-2 text-sm text-muted">Menampilkan 300 baris pertama. Gunakan "Hanya yang bermasalah" untuk melihat semua baris bermasalah.</p>
    </AppLayout>
</template>
