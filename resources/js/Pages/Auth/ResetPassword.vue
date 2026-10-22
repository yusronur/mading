<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Mascot from '@/Components/Mascot.vue';

const props = defineProps({ token: String, valid: Boolean });
const form = useForm({ password: '', password_confirmation: '' });
const submit = () => form.post(route('password.reset.update', props.token), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Buat sandi baru" />
    <GuestLayout>
        <div class="mb-6 flex flex-col items-center text-center">
            <Mascot :size="88" :mood="valid ? 'happy' : 'sad'" />
            <h1 class="mt-3 text-3xl font-bold">{{ valid ? 'Buat sandi baru' : 'Tautan tidak berlaku' }}</h1>
        </div>

        <form v-if="valid" class="animate-pop space-y-4 rounded-card border border-line bg-surface p-6 shadow-card" @submit.prevent="submit">
            <Field v-model="form.password" type="password" label="Sandi baru" :error="form.errors.password" autocomplete="new-password" autofocus hint="Minimal 8 karakter, mengandung huruf dan angka." />
            <Field v-model="form.password_confirmation" type="password" label="Ulangi sandi baru" autocomplete="new-password" />
            <Btn type="submit" size="lg" block :loading="form.processing" data-testid="save-new-password">Simpan sandi</Btn>
        </form>
        <div v-else class="space-y-4 rounded-card border border-line bg-surface p-6 text-center shadow-card">
            <p class="text-muted">Tautan reset sudah kedaluwarsa atau sudah dipakai. Minta tautan baru.</p>
            <Link :href="route('password.forgot')" class="inline-flex h-12 items-center rounded-xl bg-accent px-6 font-display font-semibold text-on-accent">Minta tautan baru</Link>
        </div>
    </GuestLayout>
</template>
