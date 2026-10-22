<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Flash from '@/Components/Flash.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Avatar from '@/Components/Avatar.vue';
import RewardToaster from '@/Components/Gamify/RewardToaster.vue';
import Bell from '@/Components/Notify/Bell.vue';
import InstallBanner from '@/Components/Notify/InstallBanner.vue';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import { syncDevice, disablePush, installable, installApp, iosHelp } from '@/lib/push';
import { applyTheme } from '@/lib/theme';

// back: { href, label } menampilkan "← Kembali ke {label}" di atas halaman anak
defineProps({ title: String, wide: Boolean, back: Object });

const page = usePage();
const user = computed(() => page.props.auth?.user);
const school = computed(() => page.props.school);
const gamify = computed(() => page.props.gamify);
const menuOpen = ref(false);

watch(() => page.props.theme, (t) => applyTheme(t), { immediate: true, deep: true });

const nav = computed(() => {
    const r = user.value?.role;
    if (r === 'guest') return [];
    if (r === 'school_admin') {
        return [
            { label: 'Beranda', route: 'admin.dashboard', icon: '🏠', active: 'admin.dashboard' },
            { label: 'Pengguna', route: 'admin.users.index', icon: '👥', active: 'admin.users.*' },
            { label: 'Impor', route: 'admin.imports.index', icon: '📥', active: 'admin.imports.*' },
            { label: 'Kartu Akun', route: 'credentials.index', icon: '🪪', active: 'credentials.*' },
            { label: 'Pesan', route: 'admin.push.index', icon: '📣', active: 'admin.push.*' },
            { label: 'Log', route: 'admin.audit.index', icon: '📜', active: 'admin.audit.*' },
            { label: 'Rombel', route: 'admin.classes.index', icon: '🏫', active: 'admin.classes.*' },
            { label: 'Tahun Ajaran', route: 'admin.years.index', icon: '📅', active: 'admin.years.*' },
            { label: 'Papan', route: 'teacher.dashboard', icon: '📌', active: 'teacher.dashboard' },
            { label: 'Kuis', route: 'quizzes.index', icon: '📝', active: ['quizzes.*', 'assignments.*'] },
            { label: 'Bank Soal', route: 'banks.index', icon: '📚', active: 'banks.*' },
            { label: 'Pengaturan', route: 'admin.settings.edit', icon: '⚙️', active: 'admin.settings.*' },
        ];
    }
    if (r === 'teacher') {
        return [
            { label: 'Papan Saya', route: 'teacher.dashboard', icon: '📌', active: 'teacher.dashboard' },
            { label: 'Kuis', route: 'quizzes.index', icon: '📝', active: ['quizzes.*', 'assignments.*'] },
            { label: 'Bank Soal', route: 'banks.index', icon: '📚', active: 'banks.*' },
            ...(user.value?.is_homeroom ? [{ label: 'Kelas Saya', route: 'credentials.index', icon: '🪪', active: 'credentials.*' }] : []),
            { label: 'Peringkat', route: 'gamify.leaderboard', icon: '🏆', active: 'gamify.leaderboard' },
            { label: 'Profil', route: 'profile.edit', icon: '🎨', active: 'profile.*' },
        ];
    }
    return [
        { label: 'Beranda', route: 'student.dashboard', icon: '🏠', active: 'student.dashboard' },
        ...(gamify.value ? [
            { label: 'Prestasi', route: 'gamify.me', icon: '🏅', active: 'gamify.me' },
            { label: 'Peringkat', route: 'gamify.leaderboard', icon: '🏆', active: 'gamify.leaderboard' },
        ] : []),
        { label: 'Profil', route: 'profile.edit', icon: '🎨', active: 'profile.*' },
    ];
});

const isActive = (pattern) => [].concat(pattern).some((p) => route().current(p));
// Perangkat bersama: keluar melepas langganan push perangkat ini agar notifikasi tidak nyasar ke pengguna berikutnya
const logout = async () => {
    await Promise.race([disablePush().catch(() => {}), new Promise((r) => setTimeout(r, 1500))]);
    router.post(route('logout'));
};
onMounted(() => { if (user.value && user.value.role !== 'guest') syncDevice(page.props.notify?.key); });
</script>

<template>
    <div class="relative min-h-dvh overflow-x-clip">
        <!-- Dekorasi latar -->
        <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="animate-blob absolute -top-24 -right-24 size-72 rounded-full bg-accent2/20 blur-3xl"></div>
            <div class="animate-blob absolute top-1/2 -left-32 size-80 rounded-full bg-accent3/15 blur-3xl" style="animation-delay:-4s"></div>
        </div>

        <Flash />
        <ConfirmDialog />
        <RewardToaster v-if="gamify" />
        <InstallBanner v-if="user && user.role !== 'guest'" />
        <Modal :show="iosHelp" title="Pasang di iPhone / iPad" @close="iosHelp = false">
            <ol class="list-decimal space-y-2 pl-5 font-semibold">
                <li>Buka Madingo di <b>Safari</b>.</li>
                <li>Ketuk tombol <b>Bagikan</b> (kotak dengan panah ke atas) di bagian bawah layar.</li>
                <li>Pilih <b>Tambahkan ke Layar Utama</b>, lalu ketuk <b>Tambah</b>.</li>
                <li>Buka Madingo dari ikon baru itu. Notifikasi bisa diaktifkan dari menu 🔔.</li>
            </ol>
            <Btn class="mt-5" block @click="iosHelp = false">Mengerti</Btn>
        </Modal>

        <header class="sticky top-0 z-30 border-b border-line/70 bg-bg/80 backdrop-blur-md">
            <div class="mx-auto flex h-16 items-center gap-3 px-4" :class="wide ? 'max-w-7xl' : 'max-w-5xl'">
                <Link :href="route('home')" class="flex min-w-0 items-center gap-2">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-accent font-display text-lg font-bold text-on-accent">M</span>
                    <span class="truncate font-display text-lg font-bold leading-tight">
                        {{ school?.display_name }}
                        <span class="hidden text-sm font-semibold text-muted sm:inline">{{ school?.name }}</span>
                    </span>
                </Link>

                <nav class="ml-4 hidden items-center gap-1 lg:flex">
                    <Link v-for="n in nav" :key="n.route" :href="route(n.route)"
                        class="rounded-lg px-3 py-2 text-sm font-bold transition"
                        :class="isActive(n.active) ? 'bg-surface2 text-ink' : 'text-muted hover:text-ink'">
                        {{ n.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                <Bell v-if="user && user.role !== 'guest'" />
                <div class="relative">
                    <button class="flex items-center gap-2 rounded-full bg-surface2 py-1 pr-3 pl-1" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen">
                        <Avatar :avatar="user?.avatar" :frame="user?.frame" :initials="user?.initials" :size="34" />
                        <span class="hidden max-w-36 truncate text-sm font-bold sm:block">{{ user?.name }}</span>
                        <span v-if="gamify" class="rounded-full bg-accent px-2 py-0.5 text-xs font-bold text-on-accent" data-testid="level-chip">Lv {{ gamify.level }}</span>
                    </button>
                    <div v-if="menuOpen" class="absolute right-0 mt-2 w-60 rounded-2xl border border-line bg-surface p-2 shadow-card" @click="menuOpen = false">
                        <div class="px-3 py-2">
                            <p class="truncate font-bold">{{ user?.name }}</p>
                            <p class="text-sm text-muted">{{ user?.role_label }}<template v-if="user?.role !== 'guest'"> · {{ user?.username }}</template></p>
                        </div>
                        <template v-if="user?.role !== 'guest'">
                            <Link :href="route('profile.edit')" class="block rounded-lg px-3 py-2 font-semibold hover:bg-surface2">🎨 Tampilan & profil</Link>
                            <Link :href="route('password.change')" class="block rounded-lg px-3 py-2 font-semibold hover:bg-surface2">🔑 Ganti sandi</Link>
                        </template>
                        <button v-if="installable && user?.role !== 'guest'" class="block w-full rounded-lg px-3 py-2 text-left font-semibold hover:bg-surface2" data-testid="menu-install" @click="installApp">⬇️ Pasang aplikasi</button>
                        <button class="block w-full rounded-lg px-3 py-2 text-left font-semibold text-danger hover:bg-surface2" @click="logout">Keluar</button>
                    </div>
                </div>
                </div>
            </div>
        </header>

        <main class="mx-auto px-4 pt-6 pb-28 lg:pb-12" :class="wide ? 'max-w-7xl' : 'max-w-5xl'">
            <Link v-if="back" :href="back.href" class="mb-4 inline-flex h-10 items-center gap-1.5 rounded-xl bg-surface2 px-3 text-sm font-bold text-muted transition hover:text-ink active:scale-95">
                <span aria-hidden="true">←</span> Kembali ke {{ back.label }}
            </Link>
            <div v-if="title || $slots.actions" class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <h1 v-if="title" class="text-3xl font-bold sm:text-4xl">{{ title }}</h1>
                <div class="flex flex-wrap gap-2"><slot name="actions" /></div>
            </div>
            <slot />
        </main>

        <!-- Navigasi bawah untuk HP -->
        <nav v-if="nav.length" class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
            <div class="mx-auto flex max-w-lg justify-around overflow-x-auto">
                <Link v-for="n in nav" :key="n.route" :href="route(n.route)"
                    class="flex min-w-16 flex-col items-center gap-0.5 px-2 py-2 text-[11px] font-bold"
                    :class="isActive(n.active) ? 'text-ink' : 'text-muted'">
                    <span class="text-xl leading-none" :class="isActive(n.active) ? 'scale-110' : 'opacity-70'">{{ n.icon }}</span>
                    {{ n.label }}
                </Link>
            </div>
        </nav>

        <footer class="pb-24 text-center text-xs text-muted lg:pb-6">
            Didukung {{ $page.props.app.brand }} · v{{ $page.props.app.version }}
        </footer>
    </div>
</template>
