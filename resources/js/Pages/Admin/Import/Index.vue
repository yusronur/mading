<script setup>
import { ref } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import { timeAgo } from '@/lib/theme';

const props = defineProps({ imports: Array, hasActiveYear: Boolean, notes: Object });
const form = useForm({ type: 'students', file: null });
const fileInput = ref(null);
const dragging = ref(false);

const pick = (e) => (form.file = e.target.files[0] ?? null);
const drop = (e) => { dragging.value = false; form.file = e.dataTransfer.files[0] ?? null; };
const submit = () => form.post(route('admin.imports.store'), { forceFormData: true });

const types = [
    { key: 'teachers', label: 'Guru', icon: '🧑‍🏫', hint: 'Impor guru dulu agar wali kelas bisa langsung ditetapkan.' },
    { key: 'students', label: 'Siswa', icon: '🎒', hint: 'Bisa langsung dari ekspor Dapodik.' },
];
const typeLabel = { students: 'Siswa', teachers: 'Guru' };
const statusTone = { preview: 'warning', processing: 'accent', done: 'success', failed: 'danger' };
const statusLabel = { preview: 'Pratinjau', processing: 'Diproses', done: 'Selesai', failed: 'Gagal' };
</script>

<template>
    <Head title="Impor data" />
    <AppLayout title="Impor guru & siswa">
        <div class="grid gap-6 lg:grid-cols-5">
            <Card class="lg:col-span-3">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <p class="mb-2 text-sm font-bold">1. Pilih jenis data</p>
                        <div class="grid grid-cols-2 gap-3">
                            <button v-for="t in types" :key="t.key" type="button"
                                class="rounded-2xl border-2 p-4 text-left transition"
                                :class="form.type === t.key ? 'border-accent bg-accent/10' : 'border-line hover:border-muted'"
                                @click="form.type = t.key">
                                <span class="text-3xl">{{ t.icon }}</span>
                                <p class="mt-1 font-display text-xl font-bold">{{ t.label }}</p>
                                <p class="text-xs text-muted">{{ t.hint }}</p>
                            </button>
                        </div>
                        <p v-if="form.type === 'students' && !hasActiveYear" class="mt-2 text-sm font-bold text-danger">
                            Belum ada tahun ajaran aktif. <Link :href="route('admin.years.index')" class="underline">Atur dulu</Link>.
                        </p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-bold">2. Unduh templat (opsional)</p>
                        <a :href="route('admin.imports.template', form.type)" class="inline-flex items-center gap-2 rounded-xl bg-surface2 px-4 py-2 font-bold hover:bg-line">⬇️ Templat {{ typeLabel[form.type] }} (.xlsx)</a>
                        <p class="mt-2 text-sm text-muted">{{ notes[form.type] }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-bold">3. Unggah berkas</p>
                        <div class="grid cursor-pointer place-items-center rounded-2xl border-2 border-dashed p-8 text-center transition"
                            :class="dragging ? 'border-accent bg-accent/10' : 'border-line hover:border-muted'"
                            @click="fileInput.click()" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="drop">
                            <span class="text-4xl">📄</span>
                            <p class="mt-2 font-bold">{{ form.file ? form.file.name : 'Klik atau seret berkas ke sini' }}</p>
                            <p class="text-sm text-muted">.xlsx, .xls, atau .csv · maks. 5 MB</p>
                            <input ref="fileInput" type="file" class="hidden" accept=".xlsx,.xls,.csv" @change="pick" />
                        </div>
                        <p v-if="form.errors.file" class="mt-1 text-sm font-semibold text-danger">{{ form.errors.file }}</p>
                    </div>

                    <Btn type="submit" size="lg" block :loading="form.processing" :disabled="!form.file">Periksa berkas →</Btn>
                    <p class="text-center text-xs text-muted">Belum ada data yang disimpan sampai Anda menekan "Proses" di halaman pratinjau.</p>
                </form>
            </Card>

            <Card class="lg:col-span-2">
                <h2 class="mb-3 text-xl font-bold">Riwayat impor</h2>
                <p v-if="!imports.length" class="text-muted">Belum ada impor.</p>
                <ul class="divide-y divide-line">
                    <li v-for="i in imports" :key="i.id">
                        <Link :href="route('admin.imports.show', i.id)" class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate font-bold">{{ typeLabel[i.type] }} · {{ i.original_name }}</p>
                                <p class="text-xs text-muted">{{ timeAgo(i.created_at) }} · {{ i.user?.name }} · {{ i.total_rows }} baris</p>
                            </div>
                            <Badge :tone="statusTone[i.status]">{{ statusLabel[i.status] }}</Badge>
                        </Link>
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
