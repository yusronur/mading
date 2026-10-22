<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Btn from '@/Components/Btn.vue';
import { installApp, installable } from '@/lib/push';

// Saran pasang aplikasi: sengaja jarang. Baru muncul di kunjungan ke-3, sekali per sesi peramban,
// jeda 14 hari setelah ditutup, dan berhenti setelah ditutup 3 kali (tombol di menu profil tetap ada).
const KEY = 'madingo.install';
const VISITS_MIN = 3;
const COOLDOWN_DAYS = 14;
const MAX_DISMISS = 3;

const show = ref(false);
let timer = null;

const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } };
const write = (v) => { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* mode privat: abaikan */ } };
const seenThisSession = () => { try { return sessionStorage.getItem(KEY) === '1'; } catch (e) { return true; } };
const markSession = () => { try { sessionStorage.setItem(KEY, '1'); } catch (e) { /* abaikan */ } };

const eligible = () => {
    const st = read();
    if ((st.dismissed ?? 0) >= MAX_DISMISS || st.installed) return false;
    if ((st.visits ?? 0) < VISITS_MIN) return false;
    return !st.last || Date.now() - st.last > COOLDOWN_DAYS * 86400000;
};

const maybeShow = () => {
    if (show.value || seenThisSession() || !installable.value || !eligible()) return;
    clearTimeout(timer);
    timer = setTimeout(() => { show.value = true; markSession(); }, 4000);
};

onMounted(() => {
    // hitung kunjungan sekali per sesi peramban
    if (!seenThisSession()) {
        const st = read();
        if (!st.counted || Date.now() - st.counted > 3600000) write({ ...st, visits: (st.visits ?? 0) + 1, counted: Date.now() });
    }
    maybeShow();
});
watch(installable, maybeShow);
onBeforeUnmount(() => clearTimeout(timer));

const close = () => {
    const st = read();
    write({ ...st, dismissed: (st.dismissed ?? 0) + 1, last: Date.now() });
    show.value = false;
};
const install = async () => {
    show.value = false;
    const ok = await installApp();
    write({ ...read(), last: Date.now(), ...(ok ? { installed: true } : {}) });
};
</script>

<template>
    <Transition enter-from-class="translate-y-4 opacity-0" leave-to-class="translate-y-4 opacity-0" enter-active-class="transition duration-300" leave-active-class="transition duration-200">
        <aside v-if="show" class="fixed inset-x-3 bottom-[4.75rem] z-30 mx-auto flex max-w-md items-center gap-3 rounded-2xl border border-line bg-surface p-3 pr-2 shadow-card lg:bottom-4 lg:right-4 lg:left-auto lg:mx-0" role="complementary" aria-label="Saran pasang aplikasi" data-testid="install-banner">
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-accent font-display text-xl font-bold text-on-accent" aria-hidden="true">M</span>
            <p class="min-w-0 flex-1 text-sm leading-snug"><b class="block">Pasang Madingo di layar utama</b><span class="text-muted">Buka lebih cepat dan terima notifikasi.</span></p>
            <Btn size="sm" data-testid="install-yes" @click="install">Pasang</Btn>
            <button type="button" class="grid size-9 shrink-0 place-items-center rounded-full text-muted hover:bg-surface2 hover:text-ink" aria-label="Tutup saran" data-testid="install-close" @click="close">✕</button>
        </aside>
    </Transition>
</template>
