<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Mascot from '@/Components/Mascot.vue';

// Masuk sebagai tamu ke kuis publik: kode + nama, tanpa akun.
const props = defineProps({ code: { type: String, default: '' } });
const form = useForm({ code: props.code, name: '' });
const submit = () => form.transform((d) => ({ ...d, code: d.code.trim().toUpperCase() })).post(route('guest.store'));
</script>

<template>
    <Head title="Ikut kuis" />
    <GuestLayout>
        <div class="mb-6 flex flex-col items-center text-center">
            <Mascot :size="96" />
            <h1 class="mt-3 text-4xl font-bold">Ikut kuis</h1>
            <p class="font-semibold text-muted">{{ $page.props.school?.name }}</p>
        </div>

        <form class="animate-pop space-y-4 rounded-card border border-line bg-surface p-6 shadow-card" @submit.prevent="submit">
            <Field v-model="form.code" label="Kode kuis" :error="form.errors.code" maxlength="6" autocapitalize="characters" autocomplete="off"
                inputmode="text" placeholder="6 huruf/angka" :autofocus="!code" class="font-mono uppercase tracking-widest" />
            <Field v-model="form.name" label="Namamu" :error="form.errors.name" maxlength="40" autocomplete="off" placeholder="mis. Budi 7A" :autofocus="!!code" />
            <p class="text-sm text-muted">Namamu akan dilihat gurumu. Tanpa akun; kamu hanya bisa mengerjakan kuis ini.</p>
            <Btn type="submit" size="lg" block :loading="form.processing" :disabled="form.code.length < 6 || form.name.trim().length < 2">Mulai 🚀</Btn>
            <p class="text-center text-sm text-muted">Punya akun sekolah? <Link :href="route('login')" class="font-bold text-ink underline">Masuk di sini</Link></p>
        </form>
    </GuestLayout>
</template>
