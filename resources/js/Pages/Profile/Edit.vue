<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';

const props = defineProps({ profile: Object, preferences: Object, themes: Array });
const form = useForm({ ...props.preferences });
const save = () => form.put(route('profile.preferences'), { preserveScroll: true });
const modes = [
    { key: 'system', label: 'Ikuti HP', icon: '📱' },
    { key: 'light', label: 'Terang', icon: '☀️' },
    { key: 'dark', label: 'Gelap', icon: '🌙' },
];
const scales = [100, 115, 130];
const prefersDark = typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches;
const pv = (t) => {
    const mode = form.color_mode === 'system' ? (prefersDark ? 'dark' : 'light') : form.color_mode;
    return t.preview[mode] ?? t.preview.light;
};
</script>

<template>
    <Head title="Profil & tampilan" />
    <AppLayout title="Profil & tampilan 🎨">
        <Card class="mb-6">
            <p class="text-2xl font-bold">{{ profile.name }}</p>
            <p class="text-muted">{{ profile.class ? `${profile.username} · Kelas ${profile.class}` : profile.username }}</p>
        </Card>

        <form class="space-y-6" @submit.prevent="save">
            <Card>
                <h2 class="mb-1 text-xl font-bold">Tema</h2>
                <p class="mb-4 text-sm text-muted">Tema lain bisa dibuka dengan XP (segera hadir).</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <button v-for="t in themes" :key="t.id" type="button" :disabled="t.locked"
                        class="overflow-hidden rounded-2xl border-4 text-left transition disabled:opacity-50"
                        :class="form.theme_id === t.id ? 'border-accent' : 'border-transparent'"
                        @click="form.theme_id = t.id">
                        <div class="flex h-24 items-end gap-2 p-3" :style="{ background: pv(t).bg }">
                            <span class="h-14 flex-1 rounded-xl" :style="{ background: pv(t).surface }"></span>
                            <span class="size-10 rounded-full" :style="{ background: pv(t).accent }"></span>
                            <span class="size-7 rounded-full" :style="{ background: pv(t).accent2 }"></span>
                        </div>
                        <p class="bg-surface2 px-3 py-2 font-display font-bold">{{ t.name }} <span v-if="t.locked">🔒</span></p>
                    </button>
                </div>
            </Card>

            <Card>
                <h2 class="mb-3 text-xl font-bold">Mode warna</h2>
                <div class="grid grid-cols-3 gap-2">
                    <button v-for="m in modes" :key="m.key" type="button"
                        class="rounded-xl border-2 py-3 font-bold"
                        :class="form.color_mode === m.key ? 'border-accent bg-accent/10' : 'border-line'"
                        @click="form.color_mode = m.key">
                        <span class="block text-2xl">{{ m.icon }}</span>{{ m.label }}
                    </button>
                </div>
            </Card>

            <Card>
                <h2 class="mb-3 text-xl font-bold">Kenyamanan</h2>
                <p class="mb-2 text-sm font-bold">Ukuran huruf</p>
                <div class="mb-4 flex gap-2">
                    <button v-for="s in scales" :key="s" type="button" class="rounded-xl border-2 px-4 py-2 font-bold"
                        :class="Number(form.font_scale) === s ? 'border-accent bg-accent/10' : 'border-line'"
                        :style="{ fontSize: `${s / 100}rem` }" @click="form.font_scale = s">Aa</button>
                </div>
                <label class="flex items-center gap-3 font-semibold">
                    <input v-model="form.reduce_motion" type="checkbox" class="size-5 accent-[var(--m-accent)]" />
                    Kurangi animasi
                </label>
            </Card>

            <Btn type="submit" size="lg" :loading="form.processing">Simpan tampilan</Btn>
        </form>
    </AppLayout>
</template>
