<script setup>
import { onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { getJson, postJson } from '@/lib/http';
import { toast } from '@/lib/toast';
import { currentSubscription, disablePush, enablePush, installEvent, installed, isIOS, isStandalone, promptInstall, pushSupported } from '@/lib/push';

const props = defineProps({ items: Array, unread: Number, push: Object });

const list = ref(props.items.map((n) => ({ ...n })));
const more = ref(props.items.length >= 30);
const loadingMore = ref(false);
const kinds = ref(props.push.kinds.map((k) => ({ ...k })));
const state = ref('checking'); // checking | unsupported | ios-install | denied | off | on
const devices = ref(props.push.devices);
const busy = ref(false);

const detect = async () => {
    if (!props.push.available) return (state.value = 'unavailable');
    if (!pushSupported()) return (state.value = isIOS() && !isStandalone() ? 'ios-install' : 'unsupported');
    if (Notification.permission === 'denied') return (state.value = 'denied');
    state.value = (await currentSubscription()) && Notification.permission === 'granted' ? 'on' : 'off';
};
onMounted(detect);

const turnOn = async () => {
    busy.value = true;
    try {
        const r = await enablePush(props.push.key);
        devices.value = r.devices;
        state.value = 'on';
        toast('Notifikasi aktif di perangkat ini.');
    } catch (e) {
        toast(e.message, 'error');
        await detect();
    } finally { busy.value = false; }
};
const turnOff = async () => {
    busy.value = true;
    const r = await disablePush();
    devices.value = r?.devices ?? Math.max(0, devices.value - 1);
    state.value = 'off';
    busy.value = false;
    toast('Notifikasi dimatikan di perangkat ini.');
};
const sendTest = async () => {
    busy.value = true;
    try {
        const r = await postJson(route('push.test'));
        toast(r.pushed ? 'Notifikasi uji dikirim. Tunggu sebentar.' : 'Tidak terkirim. Pastikan notifikasi aktif.', r.pushed ? 'success' : 'error');
    } catch (e) { toast(e.message, 'error'); } finally { busy.value = false; }
};
const savePrefs = async () => {
    try {
        await postJson(route('notifications.preferences'), { off: kinds.value.filter((k) => !k.on).map((k) => k.key) }, 'PUT');
    } catch (e) { toast(e.message, 'error'); }
};

const open = async (n) => {
    if (!n.read) {
        n.read = true;
        postJson(route('notifications.read', n.id)).catch(() => {});
    }
    if (n.url) router.visit(n.url);
};
const readAll = async () => {
    list.value.forEach((n) => (n.read = true));
    await postJson(route('notifications.read-all')).catch(() => {});
    router.reload({ only: ['unread'] });
};
const loadMore = async () => {
    loadingMore.value = true;
    try {
        const d = await getJson(route('notifications.summary', { before: list.value[list.value.length - 1].at, limit: 30 }));
        list.value.push(...d.items);
        more.value = d.items.length >= 30;
    } finally { loadingMore.value = false; }
};
const when = (iso) => new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const install = async () => { if (await promptInstall()) toast('Aplikasi terpasang. Cari ikon di layar utama.'); };
</script>

<template>
    <Head title="Notifikasi" />
    <AppLayout title="Notifikasi 🔔">
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm text-muted">Riwayat 30 hari terakhir.</p>
                    <Btn v-if="list.some((n) => !n.read)" size="sm" variant="soft" @click="readAll">Tandai semua dibaca</Btn>
                </div>
                <EmptyState v-if="!list.length" title="Belum ada notifikasi" text="Kabar kuis, nilai, dan pesan dari sekolah akan muncul di sini." />
                <ul v-else class="space-y-2">
                    <li v-for="n in list" :key="n.id">
                        <button type="button" class="flex w-full items-start gap-3 rounded-2xl border border-line bg-surface p-4 text-left transition hover:brightness-110"
                            :class="n.read ? 'opacity-70' : 'shadow-card'" data-testid="notif-row" @click="open(n)">
                            <span class="text-2xl" aria-hidden="true">{{ n.icon }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block leading-snug" :class="n.read ? 'font-semibold' : 'font-bold'">{{ n.title }}</span>
                                <span v-if="n.body" class="mt-0.5 block text-sm text-muted">{{ n.body }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ when(n.at) }}</span>
                            </span>
                            <span v-if="!n.read" class="mt-2 size-2.5 shrink-0 rounded-full bg-accent" aria-label="Belum dibaca"></span>
                        </button>
                    </li>
                </ul>
                <div v-if="more" class="mt-4 text-center"><Btn variant="soft" :loading="loadingMore" @click="loadMore">Muat lebih banyak</Btn></div>
            </section>

            <aside class="space-y-4">
                <Card>
                    <h2 class="mb-1 text-lg font-bold">Notifikasi di perangkat ini</h2>
                    <p class="mb-3 text-sm text-muted">Pemberitahuan muncul di layar walau Madingo sedang tidak dibuka. Gratis dan tanpa aplikasi tambahan.</p>

                    <p v-if="state === 'checking'" class="text-sm text-muted">Memeriksa…</p>
                    <p v-else-if="state === 'unavailable'" class="rounded-xl bg-surface2 p-3 text-sm">Notifikasi push belum diaktifkan oleh sekolah. Lonceng tetap berfungsi.</p>
                    <p v-else-if="state === 'ios-install'" class="rounded-xl bg-surface2 p-3 text-sm" data-testid="ios-hint">Di iPhone/iPad, pasang dulu aplikasinya: ketuk tombol <b>Bagikan</b> lalu <b>Tambahkan ke Layar Utama</b>, buka dari ikon itu, kemudian aktifkan notifikasi di sini. (Perlu iOS 16.4 atau lebih baru.)</p>
                    <p v-else-if="state === 'unsupported'" class="rounded-xl bg-surface2 p-3 text-sm">Peramban ini belum mendukung notifikasi push. Coba Chrome, Edge, Firefox, atau Safari terbaru.</p>
                    <p v-else-if="state === 'denied'" class="rounded-xl bg-warning/15 p-3 text-sm" data-testid="denied-hint">Izin notifikasi diblokir di peramban. Buka pengaturan situs (ikon gembok di sebelah alamat) lalu izinkan Notifikasi.</p>
                    <div v-else class="space-y-2">
                        <div v-if="state === 'off'"><Btn block :loading="busy" data-testid="push-on" @click="turnOn">🔔 Aktifkan notifikasi</Btn></div>
                        <template v-else>
                            <p class="rounded-xl bg-success/15 p-3 text-sm font-semibold" data-testid="push-active">✅ Aktif di perangkat ini<span v-if="devices > 1"> ({{ devices }} perangkat)</span></p>
                            <div class="flex gap-2"><Btn variant="soft" size="sm" :loading="busy" @click="sendTest">Kirim uji</Btn><Btn variant="ghost" size="sm" :loading="busy" @click="turnOff">Matikan</Btn></div>
                        </template>
                    </div>

                    <div v-if="kinds.length" class="mt-4 border-t border-line pt-4">
                        <p class="mb-2 text-sm font-bold">Kabar yang dikirim sebagai push</p>
                        <label v-for="k in kinds" :key="k.key" class="flex items-center gap-3 py-1.5 text-sm font-semibold">
                            <input v-model="k.on" type="checkbox" class="size-5 accent-[var(--m-accent)]" :data-testid="`pref-${k.key}`" @change="savePrefs" />{{ k.label }}
                        </label>
                        <p class="mt-2 text-xs text-muted">Maksimal {{ push.daily_cap }} push per hari. Pukul {{ push.quiet }} ditahan dan dikirim sebagai satu ringkasan pagi. Komentar dan suka hanya masuk lonceng.</p>
                    </div>
                </Card>

                <Card>
                    <h2 class="mb-1 text-lg font-bold">Pasang aplikasi</h2>
                    <p v-if="installed || isStandalone()" class="text-sm font-semibold">✅ Madingo sudah terpasang di perangkat ini.</p>
                    <template v-else>
                        <p class="mb-3 text-sm text-muted">Pasang ke layar utama supaya terbuka layar penuh seperti aplikasi, tanpa Play Store atau App Store.</p>
                        <Btn v-if="installEvent" block variant="soft" data-testid="install" @click="install">⬇️ Pasang sekarang</Btn>
                        <p v-else-if="isIOS()" class="text-sm">Ketuk <b>Bagikan</b> lalu <b>Tambahkan ke Layar Utama</b>.</p>
                        <p v-else class="text-sm text-muted">Buka menu peramban lalu pilih <b>Pasang aplikasi</b> atau <b>Tambahkan ke layar utama</b>.</p>
                    </template>
                </Card>
            </aside>
        </div>
    </AppLayout>
</template>
