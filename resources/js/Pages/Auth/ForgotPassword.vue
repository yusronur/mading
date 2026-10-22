<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Mascot from '@/Components/Mascot.vue';

const form = useForm({ username: '' });
const submit = () => form.post(route('password.send'), { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <Head title="Lupa sandi" />
    <GuestLayout>
        <div class="mb-6 flex flex-col items-center text-center">
            <Mascot :size="88" mood="wow" />
            <h1 class="mt-3 text-3xl font-bold">Lupa sandi?</h1>
            <p class="font-semibold text-muted">Kami kirim tautan reset ke email yang terdaftar di akunmu.</p>
        </div>

        <form class="animate-pop space-y-4 rounded-card border border-line bg-surface p-6 shadow-card" @submit.prevent="submit">
            <Field v-model="form.username" label="NISN / Username" :error="form.errors.username" autocomplete="username" autocapitalize="none" autofocus placeholder="contoh: 0123456789" />
            <Btn type="submit" size="lg" block :loading="form.processing" data-testid="send-reset">Kirim tautan reset ✉️</Btn>
            <p class="rounded-xl bg-surface2 px-3 py-2 text-sm text-muted">Belum punya email di akunmu? Tambahkan di halaman Profil setelah masuk, atau minta wali kelas/admin sekolah mereset sandimu.</p>
            <p class="text-center text-sm font-semibold"><Link :href="route('login')" class="font-bold text-ink underline">← Kembali ke halaman masuk</Link></p>
        </form>
    </GuestLayout>
</template>
