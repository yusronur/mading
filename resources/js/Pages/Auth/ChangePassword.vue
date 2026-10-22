<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({ forced: Boolean });
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const submit = () => form.put(route('password.update'), { onFinish: () => form.reset() });
</script>

<template>
    <Head title="Ganti sandi" />
    <GuestLayout>
        <form class="animate-pop space-y-4 rounded-card border border-line bg-surface p-6 shadow-card" @submit.prevent="submit">
            <div>
                <h1 class="text-3xl font-bold">🔑 Ganti sandi</h1>
                <p v-if="props.forced" class="mt-1 text-muted">Ini login pertamamu. Buat sandi baru yang hanya kamu ketahui.</p>
            </div>
            <Field v-model="form.current_password" label="Sandi lama / sandi awal" type="password" :error="form.errors.current_password" autocomplete="current-password" />
            <Field v-model="form.password" label="Sandi baru" type="password" :error="form.errors.password"
                hint="Minimal 8 karakter, campuran huruf dan angka." autocomplete="new-password" />
            <Field v-model="form.password_confirmation" label="Ulangi sandi baru" type="password" autocomplete="new-password" />
            <Btn type="submit" size="lg" block :loading="form.processing">Simpan sandi</Btn>
            <button v-if="props.forced" type="button" class="w-full text-sm font-semibold text-muted hover:text-ink" @click="router.post(route('logout'))">Keluar</button>
            <Btn v-else variant="ghost" block :href="route('home')">Batal</Btn>
        </form>
    </GuestLayout>
</template>
