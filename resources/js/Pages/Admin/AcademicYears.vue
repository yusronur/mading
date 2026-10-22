<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Field from '@/Components/Field.vue';
import DateField from '@/Components/DateField.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import { askConfirm } from '@/lib/confirm';

const props = defineProps({ years: Array, suggestion: Object });
const open = ref(false);
const form = useForm({ ...props.suggestion, activate: true });
const submit = () => form.post(route('admin.years.store'), { onSuccess: () => (open.value = false) });
const activate = async (y) => {
    const ok = await askConfirm({
        title: `Aktifkan ${y.name}?`,
        message: 'Rombel dan impor siswa akan memakai tahun ajaran ini.',
        confirmText: 'Aktifkan',
    });
    if (ok) router.post(route('admin.years.activate', y.id), {}, { preserveScroll: true });
};
const setSemester = (y, s) => router.put(route('admin.years.update', y.id), { active_semester: s }, { preserveScroll: true });
const fmt = (d) => new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Tahun ajaran" />
    <AppLayout title="Tahun ajaran">
        <template #actions><Btn @click="open = true">+ Tahun ajaran</Btn></template>

        <div class="grid gap-3">
            <Card v-for="y in years" :key="y.id" class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-2xl font-bold">{{ y.name }}</h2>
                        <Badge v-if="y.is_active" tone="success">Aktif</Badge>
                    </div>
                    <p class="text-sm text-muted">{{ fmt(y.starts_on) }} – {{ fmt(y.ends_on) }} · {{ y.classes_count }} rombel</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="y.is_active">
                        <span class="text-sm font-bold text-muted">Semester:</span>
                        <Btn v-for="s in [1, 2]" :key="s" size="sm" :variant="y.active_semester === s ? 'primary' : 'soft'" @click="setSemester(y, s)">{{ s === 1 ? 'Ganjil' : 'Genap' }}</Btn>
                    </template>
                    <Btn v-else size="sm" variant="soft" @click="activate(y)">Jadikan aktif</Btn>
                </div>
            </Card>
            <p v-if="!years.length" class="text-muted">Belum ada tahun ajaran.</p>
        </div>

        <Modal :show="open" title="Tahun ajaran baru" @close="open = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field v-model="form.name" label="Nama" placeholder="2026/2027" :error="form.errors.name" />
                <div class="grid grid-cols-2 gap-3">
                    <DateField v-model="form.starts_on" label="Mulai" required :error="form.errors.starts_on" />
                    <DateField v-model="form.ends_on" label="Selesai" required :error="form.errors.ends_on" :min="form.starts_on || undefined" />
                </div>
                <Field v-model="form.active_semester" as="select" label="Semester aktif">
                    <option :value="1">Ganjil (1)</option>
                    <option :value="2">Genap (2)</option>
                </Field>
                <label class="flex items-center gap-2 font-semibold">
                    <input v-model="form.activate" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Langsung jadikan aktif
                </label>
                <Btn type="submit" block :loading="form.processing">Simpan</Btn>
            </form>
        </Modal>
    </AppLayout>
</template>
