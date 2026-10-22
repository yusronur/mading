<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Avatar from '@/Components/Avatar.vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({ profile: Object, preferences: Object, themes: Array, catalog: Object, xp: Number, show_cosmetics: Boolean });
const user = usePage().props.auth.user;
const chosen = (list, key) => list.find((i) => i.key === key);
const emoji = () => chosen(props.catalog.avatars, form.avatar)?.emoji;
const ring = () => chosen(props.catalog.frames, form.frame)?.ring;
const form = useForm({ ...props.preferences });
const mail = useForm({ email: props.profile.email ?? '', current_password: '' });
const saveEmail = () => mail.put(route('profile.email'), { preserveScroll: true, onFinish: () => mail.reset('current_password') });
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

        <Card class="mb-6">
            <h2 class="mb-1 text-xl font-bold">Email untuk reset sandi</h2>
            <p class="mb-4 text-sm text-muted">Dengan email, kamu bisa mengatur ulang sandi sendiri lewat tautan yang dikirim ke sana bila lupa sandi.</p>
            <form class="space-y-3" @submit.prevent="saveEmail">
                <label class="block">
                    <span class="mb-1 block text-sm font-bold">Email</span>
                    <input v-model="mail.email" type="email" autocomplete="email" placeholder="nama@gmail.com" data-testid="profile-email"
                        class="h-12 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                    <span v-if="mail.errors.email" class="mt-1 block text-sm font-semibold text-danger">{{ mail.errors.email }}</span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-bold">Sandi saat ini (untuk konfirmasi)</span>
                    <input v-model="mail.current_password" type="password" autocomplete="current-password" data-testid="profile-email-password"
                        class="h-12 w-full rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none" />
                    <span v-if="mail.errors.current_password" class="mt-1 block text-sm font-semibold text-danger">{{ mail.errors.current_password }}</span>
                </label>
                <Btn type="submit" variant="soft" :loading="mail.processing" :disabled="!mail.current_password" data-testid="save-email">Simpan email</Btn>
            </form>
        </Card>

        <form class="space-y-6" @submit.prevent="save">
            <Card v-if="show_cosmetics" data-testid="cosmetics">
                <h2 class="mb-1 text-xl font-bold">Avatar &amp; bingkai</h2>
                <p class="mb-4 text-sm text-muted">
                    <template v-if="xp !== null">Terbuka otomatis saat XP-mu cukup. XP kamu sekarang {{ xp }}.</template>
                    <template v-else>Semua pilihan terbuka.</template>
                </p>
                <div class="mb-4 flex items-center gap-4">
                    <Avatar :avatar="emoji()" :frame="ring()" :initials="user.initials" :size="72" />
                    <button type="button" class="text-sm font-bold text-muted underline" @click="form.avatar = null; form.frame = null">Kembali ke inisial</button>
                </div>
                <p class="mb-2 text-sm font-bold">Avatar</p>
                <div class="mb-4 grid grid-cols-4 gap-2 sm:grid-cols-6">
                    <button v-for="a in catalog.avatars" :key="a.key" type="button" :disabled="a.locked" :data-testid="`avatar-${a.key}`" :title="a.locked ? `${a.name}: buka di ${a.xp} XP` : a.name"
                        class="rounded-xl border-2 p-2 text-center disabled:opacity-50" :class="form.avatar === a.key ? 'border-accent bg-accent/10' : 'border-line'" @click="form.avatar = a.key">
                        <span class="block text-3xl" :class="a.locked ? 'grayscale' : ''">{{ a.emoji }}</span>
                        <span class="block text-[11px] font-bold text-muted">{{ a.locked ? `🔒 ${a.xp} XP` : a.name }}</span>
                    </button>
                </div>
                <p class="mb-2 text-sm font-bold">Bingkai</p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                    <button v-for="f in catalog.frames" :key="f.key" type="button" :disabled="f.locked" :data-testid="`frame-${f.key}`"
                        class="flex flex-col items-center gap-1 rounded-xl border-2 p-2 disabled:opacity-50" :class="form.frame === f.key ? 'border-accent bg-accent/10' : 'border-line'" @click="form.frame = f.key">
                        <Avatar :avatar="emoji()" :frame="f.ring" :initials="user.initials" :size="44" />
                        <span class="text-[11px] font-bold text-muted">{{ f.locked ? `🔒 ${f.xp} XP` : f.name }}</span>
                    </button>
                </div>
            </Card>

            <Card>
                <h2 class="mb-1 text-xl font-bold">Tema</h2>
                <p class="mb-4 text-sm text-muted">Tema bertanda 🔒 terbuka otomatis saat XP-mu cukup.</p>
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
                        <p class="bg-surface2 px-3 py-2 font-display font-bold">{{ t.name }} <span v-if="t.locked" class="text-xs text-muted">🔒 {{ t.xp_required }} XP</span></p>
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
