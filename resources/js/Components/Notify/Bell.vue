<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { getJson, postJson } from '@/lib/http';

// Lonceng pusat notifikasi. Angka belum dibaca ikut di setiap navigasi (props bersama),
// ditambah pengecekan tiap menit saat halaman terlihat. Tanpa WebSocket.
const page = usePage();
const unread = ref(page.props.notify?.unread ?? 0);
const open = ref(false);
const items = ref([]);
const loading = ref(false);
const root = ref(null);
let timer = null;

watch(() => page.props.notify?.unread, (n) => { if (n !== undefined) unread.value = n; });

const badge = computed(() => (unread.value > 99 ? '99+' : unread.value));

const load = async () => {
    try {
        const d = await getJson(route('notifications.summary'));
        unread.value = d.unread;
        items.value = d.items;
    } catch (e) { /* diam: lonceng tidak boleh mengganggu halaman */ }
};

const toggle = async () => {
    open.value = !open.value;
    if (open.value) {
        loading.value = !items.value.length;
        await load();
        loading.value = false;
    }
};

const go = async (n) => {
    open.value = false;
    if (!n.read) {
        n.read = true;
        unread.value = Math.max(0, unread.value - 1);
        postJson(route('notifications.read', n.id)).catch(() => {});
    }
    if (n.url) router.visit(n.url);
};

const readAll = async () => {
    items.value.forEach((n) => (n.read = true));
    unread.value = 0;
    postJson(route('notifications.read-all')).catch(() => {});
};

const ago = (iso) => {
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'baru saja';
    if (s < 3600) return `${Math.floor(s / 60)} mnt lalu`;
    if (s < 86400) return `${Math.floor(s / 3600)} jam lalu`;
    return `${Math.floor(s / 86400)} hari lalu`;
};

const onDoc = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
const onKey = (e) => { if (e.key === 'Escape') open.value = false; };
const poll = () => { if (!document.hidden) load(); };

onMounted(() => {
    document.addEventListener('click', onDoc);
    document.addEventListener('keydown', onKey);
    timer = setInterval(poll, 60000);
    document.addEventListener('visibilitychange', poll);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDoc);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('visibilitychange', poll);
    clearInterval(timer);
});
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" class="relative grid size-10 place-items-center rounded-full bg-surface2 text-xl transition hover:brightness-110 active:scale-95"
            :aria-label="unread ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'" :aria-expanded="open" data-testid="bell" @click.stop="toggle">
            <span aria-hidden="true">🔔</span>
            <span v-if="unread" class="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-danger px-1 text-[11px] leading-5 font-bold text-white" data-testid="bell-count">{{ badge }}</span>
        </button>

        <div v-if="open" class="fixed inset-x-3 top-[4.25rem] z-40 rounded-2xl border border-line bg-surface p-2 shadow-card sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-96" role="dialog" aria-label="Notifikasi" data-testid="bell-panel">
            <div class="flex items-center justify-between px-3 py-2">
                <p class="font-display font-bold">Notifikasi</p>
                <button v-if="unread" type="button" class="text-sm font-bold text-accent hover:underline" @click="readAll">Tandai semua dibaca</button>
            </div>
            <div class="max-h-[60dvh] overflow-y-auto">
                <p v-if="loading" class="px-3 py-6 text-center text-sm text-muted">Memuat…</p>
                <p v-else-if="!items.length" class="px-3 py-8 text-center text-sm text-muted">Belum ada notifikasi. 🎈</p>
                <button v-for="n in items" :key="n.id" type="button" class="flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-surface2"
                    :class="n.read ? 'opacity-70' : ''" data-testid="bell-item" @click="go(n)">
                    <span class="mt-0.5 text-xl" aria-hidden="true">{{ n.icon }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm leading-snug" :class="n.read ? 'font-semibold' : 'font-bold'">{{ n.title }}</span>
                        <span v-if="n.body" class="mt-0.5 block truncate text-xs text-muted">{{ n.body }}</span>
                        <span class="mt-0.5 block text-xs text-muted">{{ ago(n.at) }}</span>
                    </span>
                    <span v-if="!n.read" class="mt-2 size-2.5 shrink-0 rounded-full bg-accent" aria-label="Belum dibaca"></span>
                </button>
            </div>
            <Link :href="route('notifications.index')" class="mt-1 block rounded-xl bg-surface2 px-3 py-2.5 text-center text-sm font-bold hover:brightness-110" @click="open = false">Lihat semua &amp; pengaturan →</Link>
        </div>
    </div>
</template>
