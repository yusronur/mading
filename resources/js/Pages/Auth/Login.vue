<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Mascot from '@/Components/Mascot.vue';

const form = useForm({ username: '', password: '', remember: true });
const submit = () => form.post(route('login.store'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Masuk" />
    <GuestLayout>
        <div class="mb-6 flex flex-col items-center text-center">
            <Mascot :size="104" />
            <h1 class="mt-3 text-4xl font-bold">{{ $page.props.school?.display_name }}</h1>
            <p class="font-semibold text-muted">{{ $page.props.school?.name }}</p>
        </div>

        <form class="animate-pop space-y-4 rounded-card border border-line bg-surface p-6 shadow-card" @submit.prevent="submit">
            <Field v-model="form.username" label="NISN / Username" :error="form.errors.username"
                autocomplete="username" autocapitalize="none" inputmode="text" autofocus placeholder="contoh: 0123456789" />
            <Field v-model="form.password" label="Kata sandi" type="password" :error="form.errors.password"
                autocomplete="current-password" />
            <label class="flex items-center gap-2 text-sm font-semibold text-muted">
                <input v-model="form.remember" type="checkbox" class="size-5 accent-[var(--m-accent)]" />
                Tetap masuk di perangkat ini (30 hari)
            </label>
            <Btn type="submit" size="lg" block :loading="form.processing">Masuk 🚀</Btn>
            <p class="text-center text-sm text-muted">Lupa sandi? Minta wali kelas atau admin sekolah untuk mereset.</p>
            <p class="rounded-xl bg-surface2 px-3 py-2 text-center text-sm font-semibold">📱 Punya kartu QR? Pindai saja dengan kamera HP, langsung masuk.</p>
            <p class="text-center text-sm font-semibold">Dapat kode kuis dari guru? <Link :href="route('guest.join')" class="font-bold text-ink underline">Ikut kuis tanpa akun</Link></p>
        </form>
    </GuestLayout>
</template>
