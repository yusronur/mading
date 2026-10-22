<script setup>
import { ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Field from '@/Components/Field.vue';
import Btn from '@/Components/Btn.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({ year: Object, classes: Array, teachers: Array });
const open = ref(false);
const form = useForm({ name: '', grade: null, homeroom_teacher_id: null });
const submit = () => form.post(route('admin.classes.store'), { onSuccess: () => { open.value = false; form.reset(); } });
const setHomeroom = (c, id) => router.put(route('admin.classes.update', c.id), { homeroom_teacher_id: id || null, grade: c.grade }, { preserveScroll: true });
</script>

<template>
    <Head title="Rombel" />
    <AppLayout :title="`Rombel ${year?.name ?? ''}`">
        <template #actions><Btn v-if="year" @click="open = true">+ Rombel</Btn></template>

        <EmptyState v-if="!year" title="Belum ada tahun ajaran aktif" text="Buat tahun ajaran terlebih dahulu.">
            <Btn :href="route('admin.years.index')">Atur tahun ajaran</Btn>
        </EmptyState>
        <EmptyState v-else-if="!classes.length" title="Belum ada rombel" text="Rombel dibuat otomatis saat impor siswa, atau tambahkan manual.">
            <Btn :href="route('admin.imports.index')">Impor siswa</Btn>
        </EmptyState>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="c in classes" :key="c.id">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-3xl font-bold">{{ c.name }}</h2>
                        <Link :href="route('admin.users.index', { class_id: c.id, role: 'student' })" class="text-sm font-semibold text-muted hover:text-ink">{{ c.members_count }} siswa →</Link>
                    </div>
                    <span class="rounded-xl bg-surface2 px-2.5 py-1 text-sm font-bold text-muted">Kelas {{ c.grade ?? '?' }}</span>
                </div>
                <label class="mt-4 block text-xs font-bold text-muted uppercase">Wali kelas</label>
                <select class="mt-1 h-10 w-full rounded-xl border-2 border-line bg-surface px-2 text-sm text-ink"
                    :value="c.homeroom_teacher_id ?? ''" @change="setHomeroom(c, $event.target.value)">
                    <option value="">— belum ditetapkan —</option>
                    <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </Card>
        </div>

        <Modal :show="open" title="Rombel baru" @close="open = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field v-model="form.name" label="Nama rombel" placeholder="7A" :error="form.errors.name" />
                <Field v-model="form.grade" type="number" label="Tingkat (opsional)" min="1" max="12" hint="Kosongkan untuk ditebak dari nama." />
                <Field v-model="form.homeroom_teacher_id" as="select" label="Wali kelas (opsional)">
                    <option :value="null">— nanti saja —</option>
                    <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                </Field>
                <Btn type="submit" block :loading="form.processing">Simpan</Btn>
            </form>
        </Modal>
    </AppLayout>
</template>
