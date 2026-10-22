<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import Btn from '@/Components/Btn.vue';
import { timeAgo } from '@/lib/theme';

defineProps({ stats: Object, year: Object, recentImports: Array });

const tiles = (s) => [
    { label: 'Guru aktif', value: s.teachers, icon: '🧑‍🏫', tone: 'bg-accent3/20' },
    { label: 'Siswa aktif', value: s.students, icon: '🎒', tone: 'bg-accent2/20' },
    { label: 'Rombel', value: s.classes, icon: '🏫', tone: 'bg-accent/20' },
    { label: 'Papan', value: s.boards, icon: '📌', tone: 'bg-warning/20' },
];
const typeLabel = { students: 'Siswa', teachers: 'Guru' };
const statusTone = { preview: 'warning', processing: 'accent', done: 'success', failed: 'danger' };
const statusLabel = { preview: 'Pratinjau', processing: 'Diproses', done: 'Selesai', failed: 'Gagal' };
</script>

<template>
    <Head title="Admin" />
    <AppLayout title="Halo, Admin 👋">
        <template #actions>
            <Btn :href="route('admin.imports.index')">📥 Impor data</Btn>
        </template>

        <Card v-if="!year" class="mb-6 border-warning">
            <p class="font-bold">Belum ada tahun ajaran aktif.</p>
            <p class="text-muted">Buat tahun ajaran sebelum mengimpor siswa.</p>
            <Btn class="mt-3" size="sm" :href="route('admin.years.index')">Atur tahun ajaran</Btn>
        </Card>
        <p v-else class="mb-6 text-muted">Tahun ajaran aktif: <span class="font-bold text-ink">{{ year.name }}</span> · Semester {{ year.active_semester }}</p>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <Card v-for="t in tiles(stats)" :key="t.label" class="animate-pop">
                <div class="mb-3 grid size-11 place-items-center rounded-2xl text-2xl" :class="t.tone">{{ t.icon }}</div>
                <p class="font-display text-4xl font-bold">{{ t.value }}</p>
                <p class="text-sm font-semibold text-muted">{{ t.label }}</p>
            </Card>
        </div>

        <div class="mt-8 grid gap-4 lg:grid-cols-2">
            <Card>
                <h2 class="mb-3 text-xl font-bold">Langkah awal</h2>
                <ol class="space-y-2 text-muted">
                    <li><Link :href="route('admin.settings.edit')" class="font-bold text-ink underline decoration-accent decoration-2 underline-offset-4">1. Atur nama aplikasi dan tema sekolah</Link></li>
                    <li><Link :href="route('admin.years.index')" class="font-bold text-ink underline decoration-accent decoration-2 underline-offset-4">2. Pastikan tahun ajaran aktif benar</Link></li>
                    <li><Link :href="route('admin.imports.index')" class="font-bold text-ink underline decoration-accent decoration-2 underline-offset-4">3. Impor guru, lalu impor siswa</Link></li>
                    <li><Link :href="route('admin.classes.index')" class="font-bold text-ink underline decoration-accent decoration-2 underline-offset-4">4. Tetapkan wali kelas</Link></li>
                    <li><Link :href="route('admin.users.index')" class="font-bold text-ink underline decoration-accent decoration-2 underline-offset-4">5. Bagikan akun ke guru dan wali kelas</Link></li>
                </ol>
            </Card>
            <Card>
                <h2 class="mb-3 text-xl font-bold">Impor terakhir</h2>
                <p v-if="!recentImports.length" class="text-muted">Belum ada impor.</p>
                <ul class="divide-y divide-line">
                    <li v-for="i in recentImports" :key="i.id" class="flex items-center justify-between gap-3 py-2.5">
                        <Link :href="route('admin.imports.show', i.id)" class="min-w-0">
                            <p class="truncate font-bold">{{ typeLabel[i.type] }} · {{ i.original_name }}</p>
                            <p class="text-sm text-muted">{{ timeAgo(i.created_at) }} · +{{ i.created_count }} baru, {{ i.updated_count }} diperbarui</p>
                        </Link>
                        <Badge :tone="statusTone[i.status]">{{ statusLabel[i.status] }}</Badge>
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
