<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { askConfirm } from '@/lib/confirm';
import { timeAgo } from '@/lib/theme';

const props = defineProps({ classes: Array, classId: String, canAll: Boolean, students: Array, stats: Object });
const isAdmin = computed(() => usePage().props.auth.user.role === 'school_admin');

const selected = computed({
    get: () => props.classId ?? '',
    set: (v) => router.get(route('credentials.index'), v ? { class_id: v } : {}, { preserveState: true, preserveScroll: true }),
});
const scopeName = computed(() => props.classes.find((c) => c.id === props.classId)?.name ?? 'seluruh sekolah');
const toBeChanged = computed(() => props.stats.ready + props.stats.missing);
const printable = computed(() => props.stats.ready + props.stats.qr > 0);
const printParams = computed(() => (props.classId ? { class_id: props.classId } : { all: 1 }));
const opts = { preserveScroll: true };

const generate = async (regenerate = false) => {
    const n = regenerate ? toBeChanged.value : props.stats.missing;
    const ok = await askConfirm({
        title: regenerate ? `Buat ulang ${n} sandi awal?` : `Buat sandi awal untuk ${n} siswa?`,
        message: regenerate
            ? `Sandi awal ${scopeName.value} yang belum diganti siswa akan diganti baru. Kartu yang sudah dicetak tidak berlaku lagi.`
            : `Hanya siswa ${scopeName.value} yang belum pernah mengganti sandi dan belum punya sandi awal.`,
        confirmText: regenerate ? 'Buat ulang' : 'Buat sandi',
        tone: regenerate ? 'danger' : 'primary',
    });
    if (ok) router.post(route('credentials.generate'), { class_id: props.classId, regenerate }, opts);
};

const qr = async (action, s = null) => {
    const who = s ? s.name : scopeName.value;
    const text = {
        generate: [`Buat kode QR untuk ${props.stats.total - props.stats.qr} siswa?`, 'Siswa bisa masuk hanya dengan memindai kartu, tanpa mengetik NISN dan sandi.', 'Buat QR', 'primary'],
        regenerate: [`Buat ulang kode QR ${who}?`, 'Kode QR lama langsung tidak berlaku. Pakai ini bila kartu hilang atau dipinjam orang lain.', 'Buat ulang', 'danger'],
        revoke: [`Cabut kode QR ${who}?`, 'Kartu QR tidak bisa dipakai lagi. Siswa tetap bisa masuk dengan NISN dan sandi.', 'Cabut', 'danger'],
    }[action];
    const ok = await askConfirm({ title: text[0], message: text[1], confirmText: text[2], tone: text[3] });
    if (ok) router.post(route('credentials.qr'), { class_id: props.classId, user_id: s?.id, action }, opts);
};

const regenerateOne = async (s) => {
    const ok = await askConfirm({ title: `Buat ulang sandi awal ${s.name}?`, message: 'Sandi awal yang lama tidak berlaku lagi.', confirmText: 'Buat ulang' });
    if (ok) router.post(route('credentials.generate'), { class_id: props.classId, user_id: s.id }, opts);
};
const reset = async (s) => {
    const ok = await askConfirm({
        title: `Reset sandi ${s.name}?`,
        message: 'Untuk siswa yang lupa sandi. Sandi lama tidak berlaku; siswa mendapat sandi awal baru yang wajib diganti saat masuk.',
        confirmText: 'Reset sandi', tone: 'danger',
    });
    if (ok) router.post(route('credentials.reset'), { class_id: props.classId, user_id: s.id }, opts);
};

const state = {
    changed: { tone: 'success', label: 'Sudah ganti sandi' },
    ready: { tone: 'accent', label: 'Sandi awal siap' },
    missing: { tone: 'warning', label: 'Belum ada sandi awal' },
};
const linkBtn = 'inline-flex items-center rounded-[calc(var(--m-radius)*0.7)] font-display font-semibold';
</script>

<template>
    <Head :title="isAdmin ? 'Kartu akun' : 'Kelas saya'" />
    <AppLayout :title="isAdmin ? 'Kartu akun siswa 🪪' : 'Kelas saya 🪪'" wide>
        <p class="-mt-3 mb-5 max-w-3xl text-muted">
            Cetak kartu berisi <b class="text-ink">kode QR</b> (masuk tanpa mengetik) dan/atau <b class="text-ink">sandi awal</b> (wajib diganti saat masuk pertama), per rombel atau per siswa.
            Keduanya tersimpan terenkripsi, jadi kartu bisa dicetak ulang kapan saja. Kartu hilang? Buat ulang QR siswa itu; kartu lama langsung tidak berlaku.
        </p>

        <div class="mb-5 flex flex-wrap items-end gap-3">
            <label class="block">
                <span class="mb-1.5 block text-sm font-bold">Rombel</span>
                <select v-model="selected" class="h-12 min-w-56 rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none">
                    <option v-if="canAll" value="">Seluruh sekolah</option>
                    <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <Card><p class="font-display text-3xl font-bold">{{ stats.total }}</p><p class="text-sm font-semibold text-muted">siswa aktif</p></Card>
            <Card><p class="font-display text-3xl font-bold text-accent3">{{ stats.qr }}</p><p class="text-sm font-semibold text-muted">punya kode QR</p></Card>
            <Card><p class="font-display text-3xl font-bold text-success">{{ stats.changed }}</p><p class="text-sm font-semibold text-muted">sudah ganti sandi</p></Card>
            <Card><p class="font-display text-3xl font-bold text-accent">{{ stats.ready }}</p><p class="text-sm font-semibold text-muted">sandi awal siap</p></Card>
            <Card><p class="font-display text-3xl font-bold text-warning">{{ stats.missing }}</p><p class="text-sm font-semibold text-muted">belum punya sandi awal</p></Card>
        </div>

        <Card class="mb-6 space-y-4 border-accent">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-lg font-bold">{{ scopeName === 'seluruh sekolah' ? 'Seluruh sekolah' : 'Rombel ' + scopeName }}</p>
                <a v-if="printable" :href="route('credentials.print', printParams)" target="_blank" rel="noopener"
                    :class="linkBtn" class="h-11 bg-accent px-5 text-on-accent shadow-card hover:brightness-110">🖨️ Cetak kartu</a>
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-2xl bg-surface2/60 p-4">
                    <p class="mb-2 font-bold">📱 Kode QR login</p>
                    <div class="flex flex-wrap gap-2">
                        <Btn size="sm" :disabled="stats.qr >= stats.total" @click="qr('generate')">Buat QR ({{ stats.total - stats.qr }})</Btn>
                        <Btn size="sm" variant="soft" :disabled="!stats.qr" @click="qr('regenerate')">Buat ulang semua</Btn>
                        <Btn size="sm" variant="danger" :disabled="!stats.qr" @click="qr('revoke')">Cabut semua</Btn>
                    </div>
                </div>
                <div class="rounded-2xl bg-surface2/60 p-4">
                    <p class="mb-2 font-bold">🔑 Sandi awal ({{ toBeChanged }} belum ganti sandi)</p>
                    <div class="flex flex-wrap gap-2">
                        <Btn size="sm" :disabled="!stats.missing" @click="generate(false)">Buat sandi awal ({{ stats.missing }})</Btn>
                        <Btn size="sm" variant="soft" :disabled="!toBeChanged" @click="generate(true)">Buat ulang semua</Btn>
                    </div>
                </div>
            </div>
        </Card>

        <EmptyState v-if="!classId" title="Pilih rombel untuk melihat daftar siswa" text="Atau langsung buat dan cetak kartu seluruh sekolah dengan tombol di atas." />
        <EmptyState v-else-if="!students.length" title="Rombel ini belum punya siswa aktif" text="Impor data siswa terlebih dahulu." />

        <Card v-else :padded="false" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface2 text-xs uppercase text-muted">
                        <tr>
                            <th class="px-4 py-3">Nama</th>
                            <th class="px-4 py-3">NISN</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Login terakhir</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="s in students" :key="s.id">
                            <td class="px-4 py-3 font-bold">{{ s.name }}</td>
                            <td class="px-4 py-3 font-mono text-muted">{{ s.username }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge :tone="state[s.state].tone">{{ state[s.state].label }}</Badge>
                                    <Badge v-if="s.has_qr" tone="neutral">QR aktif</Badge>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ s.last_login_at ? timeAgo(s.last_login_at) : 'belum pernah' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <Btn size="sm" variant="soft" @click="s.has_qr ? qr('regenerate', s) : qr('generate', s)">{{ s.has_qr ? 'QR baru' : 'Buat QR' }}</Btn>
                                    <Btn v-if="s.state === 'changed'" size="sm" variant="soft" @click="reset(s)">Reset sandi</Btn>
                                    <Btn v-else size="sm" variant="soft" @click="regenerateOne(s)">{{ s.state === 'ready' ? 'Sandi baru' : 'Buat sandi' }}</Btn>
                                    <a v-if="s.has_qr || s.state === 'ready'" :href="route('credentials.print', { class_id: classId, user_id: s.id })" target="_blank" rel="noopener"
                                        :class="linkBtn" class="h-9 bg-surface2 px-3 text-sm hover:bg-line/60">🖨️ Kartu</a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AppLayout>
</template>
