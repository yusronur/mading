<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import { askConfirm } from '@/lib/confirm';
import { copyText } from '@/lib/clipboard';
import { toast } from '@/lib/toast';
import { timeAgo } from '@/lib/theme';

const props = defineProps({ users: Object, filters: Object, classes: Array, credential: Object });
const f = reactive({ role: props.filters.role ?? '', class_id: props.filters.class_id ?? '', q: props.filters.q ?? '', status: props.filters.status ?? '' });

let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
        router.get(route('admin.users.index'), params, { preserveState: true, replace: true });
    }, 300);
});

// Dialog sandi awal: tetap terbuka sampai ditutup admin (tidak hilang sendiri)
const shown = ref(props.credential ?? null);
watch(() => props.credential, (c) => { if (c) shown.value = c; });

const reset = async (u) => {
    const ok = await askConfirm({
        title: `Reset sandi ${u.name}?`,
        message: 'Sandi lama tidak berlaku lagi. Sandi awal yang baru wajib diganti saat masuk, dan bisa dicetak sebagai kartu akun.',
        confirmText: 'Reset sandi',
        tone: 'danger',
    });
    if (ok) router.post(route('admin.users.reset-password', u.id), {}, { preserveScroll: true });
};
const toggle = async (u) => {
    const status = u.status === 'active' ? 'inactive' : 'active';
    const ok = await askConfirm({
        title: `${status === 'active' ? 'Aktifkan' : 'Nonaktifkan'} akun ${u.name}?`,
        message: status === 'active' ? '' : 'Pengguna tidak bisa masuk sampai akunnya diaktifkan kembali.',
        confirmText: status === 'active' ? 'Aktifkan' : 'Nonaktifkan',
        tone: status === 'active' ? 'primary' : 'danger',
    });
    if (ok) router.put(route('admin.users.status', u.id), { status }, { preserveScroll: true });
};
const copyPassword = async () => {
    const ok = await copyText(shown.value.password);
    toast(ok ? 'Sandi awal tersalin' : 'Gagal menyalin, salin manual', ok ? 'success' : 'error');
};
// Tambah pengguna manual (tanpa impor)
const adding = ref(false);
const add = useForm({ role: 'student', username: '', name: '', gender: '', class_id: '' });
const openAdd = () => { add.reset(); add.clearErrors(); adding.value = true; };
const submitAdd = () => add.post(route('admin.users.store'), { preserveScroll: true, onSuccess: () => { adding.value = false; } });

const roleTone = { school_admin: 'warning', teacher: 'accent', student: 'neutral' };
</script>

<template>
    <Head title="Pengguna" />
    <AppLayout title="Pengguna" wide>
        <template #actions>
            <Btn @click="openAdd">➕ Tambah pengguna</Btn>
            <Btn :href="route('credentials.index')" variant="soft">🪪 Kartu akun</Btn>
            <Btn :href="route('admin.imports.index')" variant="soft">📥 Impor</Btn>
        </template>

        <div class="mb-4 grid gap-2 sm:grid-cols-4">
            <input v-model="f.q" type="search" placeholder="Cari nama / NISN / username"
                class="h-11 rounded-xl border-2 border-line bg-surface px-4 text-ink sm:col-span-2" />
            <select v-model="f.role" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink">
                <option value="">Semua peran</option>
                <option value="student">Siswa</option>
                <option value="teacher">Guru</option>
                <option value="school_admin">Admin</option>
            </select>
            <select v-model="f.class_id" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink">
                <option value="">Semua rombel</option>
                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </div>

        <Card :padded="false" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface2 text-xs uppercase text-muted">
                        <tr>
                            <th class="px-4 py-3">Nama</th>
                            <th class="px-4 py-3">Username</th>
                            <th class="px-4 py-3">Peran</th>
                            <th class="px-4 py-3">Rombel</th>
                            <th class="px-4 py-3">Login terakhir</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in users.data" :key="u.id" :class="u.status !== 'active' ? 'opacity-55' : ''">
                            <td class="px-4 py-3 font-bold">
                                {{ u.name }}
                                <Badge v-if="u.status !== 'active'" tone="danger" class="ml-1">nonaktif</Badge>
                                <Badge v-else-if="u.must_change_password" tone="warning" class="ml-1">sandi awal</Badge>
                            </td>
                            <td class="px-4 py-3 font-mono text-muted">{{ u.username }}</td>
                            <td class="px-4 py-3"><Badge :tone="roleTone[u.role]">{{ u.role_label }}</Badge></td>
                            <td class="px-4 py-3">{{ u.class ?? '–' }}</td>
                            <td class="px-4 py-3 text-muted">{{ u.last_login_at ? timeAgo(u.last_login_at) : 'belum pernah' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <a v-if="u.has_initial_password" :href="route('credentials.print', { user_id: u.id })" target="_blank" rel="noopener"
                                        class="inline-flex h-9 items-center rounded-[calc(var(--m-radius)*0.7)] bg-surface2 px-3 text-sm font-semibold hover:bg-line/60">🖨️ Kartu</a>
                                    <Btn size="sm" variant="soft" @click="reset(u)">Reset sandi</Btn>
                                    <Btn size="sm" :variant="u.status === 'active' ? 'danger' : 'soft'" @click="toggle(u)">{{ u.status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</Btn>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!users.data.length"><td colspan="6" class="px-4 py-10 text-center text-muted">Tidak ada pengguna yang cocok.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted">{{ users.total }} pengguna</p>
            <Pagination :links="users.links" />
        </div>

        <Modal :show="adding" title="Tambah pengguna" persistent @close="adding = false">
            <form class="space-y-4" @submit.prevent="submitAdd">
                <div class="grid grid-cols-2 gap-2" role="group" aria-label="Peran">
                    <button v-for="r in [['student', 'Siswa'], ['teacher', 'Guru']]" :key="r[0]" type="button" class="rounded-xl border-2 px-3 py-2.5 font-bold"
                        :class="add.role === r[0] ? 'border-accent bg-accent text-on-accent' : 'border-line'" :aria-pressed="add.role === r[0]" @click="add.role = r[0]">{{ r[1] }}</button>
                </div>
                <Field v-model="add.username" :label="add.role === 'student' ? 'NISN' : 'NIP / username'" :error="add.errors.username" autocomplete="off" autocapitalize="none"
                    :placeholder="add.role === 'student' ? 'mis. 0123456789' : 'mis. 198001012005011001 atau budi.s'" hint="Dipakai untuk masuk. Huruf, angka, titik, garis bawah, strip." />
                <Field v-model="add.name" label="Nama lengkap" :error="add.errors.name" autocomplete="off" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <Field v-model="add.gender" as="select" label="Jenis kelamin (opsional)">
                        <option value="">–</option><option value="L">Laki-laki</option><option value="P">Perempuan</option>
                    </Field>
                    <Field v-model="add.class_id" as="select" :label="add.role === 'student' ? 'Rombel' : 'Wali kelas (opsional)'" :error="add.errors.class_id">
                        <option value="">{{ add.role === 'student' ? 'Pilih rombel' : 'Bukan wali kelas' }}</option>
                        <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </Field>
                </div>
                <p class="text-sm text-muted">Sandi awal dibuat otomatis dan ditampilkan setelah disimpan; wajib diganti saat masuk pertama.</p>
                <Btn type="submit" block size="lg" :loading="add.processing">Buat akun</Btn>
            </form>
        </Modal>

        <Modal :show="!!shown" title="Sandi awal baru" @close="shown = null">
            <div v-if="shown" class="space-y-4">
                <p class="text-muted">
                    <span class="font-bold text-ink">{{ shown.name }}</span> · {{ shown.role_label }}<span v-if="shown.class"> · {{ shown.class }}</span><br />
                    Username: <span class="font-mono font-bold text-ink">{{ shown.username }}</span>
                </p>
                <button type="button" class="w-full rounded-2xl border-2 border-dashed border-accent bg-surface2 px-4 py-5 text-center" title="Salin sandi" @click="copyPassword">
                    <span class="block text-xs font-bold text-muted uppercase">Sandi awal (ketuk untuk menyalin)</span>
                    <span class="font-mono text-4xl font-bold tracking-[0.2em] select-all">{{ shown.password }}</span>
                </button>
                <p class="rounded-xl bg-warning/15 px-4 py-2 text-sm font-semibold text-warning">
                    Wajib diganti saat masuk pertama. Sandi ini tetap tersimpan sampai pengguna menggantinya, jadi dialog ini aman ditutup; kartunya bisa dicetak lagi lewat tombol 🖨️ Kartu.
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <Btn variant="ghost" @click="shown = null">Tutup</Btn>
                    <a :href="route('credentials.print', { user_id: shown.id })" target="_blank" rel="noopener"
                        class="inline-flex h-11 items-center rounded-[calc(var(--m-radius)*0.7)] bg-accent px-5 font-display font-semibold text-on-accent shadow-card hover:brightness-110">🖨️ Cetak kartu</a>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
