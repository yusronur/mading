<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ logs: Object, filters: Object, groups: Object });
const f = reactive({ group: props.filters.group ?? '', q: props.filters.q ?? '' });

let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => {
        router.get(route('admin.audit.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
    }, 300);
});

const tone = (a) => (a.startsWith('auth.lockout') || a.endsWith('revoked') || a.endsWith('deleted') ? 'danger' : a.startsWith('auth') ? 'accent' : 'neutral');
const fmt = (iso) => new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const detail = (m) => {
    if (!m) return '';
    const keys = { count: 'jumlah', username: 'username', title: 'judul', file: 'berkas', created: 'baru', updated: 'diperbarui', status: 'status', action: 'aksi', user: 'guru', name: 'nama' };
    return Object.entries(m).filter(([k, v]) => keys[k] && v !== null && v !== '').map(([k, v]) => `${keys[k]}: ${v}`).join(' · ');
};
</script>

<template>
    <Head title="Log aktivitas" />
    <AppLayout title="Log aktivitas 📜" wide>
        <p class="-mt-3 mb-5 text-muted">Catatan tindakan penting: reset sandi, kartu akun, impor, penguncian akun, papan dihapus, moderasi.</p>
        <div class="mb-4 grid gap-2 sm:grid-cols-3">
            <input v-model="f.q" type="search" placeholder="Cari nama, username, atau isi" class="h-11 rounded-xl border-2 border-line bg-surface px-4 text-ink sm:col-span-2" />
            <select v-model="f.group" class="h-11 rounded-xl border-2 border-line bg-surface px-3 text-ink" aria-label="Jenis aktivitas">
                <option value="">Semua aktivitas</option>
                <option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option>
            </select>
        </div>
        <Card :padded="false" class="overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="l in logs.data" :key="l.id" class="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
                    <div class="min-w-0">
                        <p class="font-bold"><Badge :tone="tone(l.action)" class="mr-1">{{ l.label }}</Badge> <span v-if="l.target">{{ l.target }}</span></p>
                        <p class="mt-0.5 text-sm text-muted">oleh {{ l.actor }}<span v-if="detail(l.meta)"> · {{ detail(l.meta) }}</span></p>
                    </div>
                    <p class="shrink-0 text-right text-xs text-muted">{{ fmt(l.created_at) }}<br /><span class="font-mono">{{ l.ip }}</span></p>
                </li>
                <li v-if="!logs.data.length" class="px-4 py-10 text-center text-muted">Belum ada catatan.</li>
            </ul>
        </Card>
        <div class="mt-4 flex justify-end"><Pagination :links="logs.links" /></div>
    </AppLayout>
</template>
