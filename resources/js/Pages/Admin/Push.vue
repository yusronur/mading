<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Field from '@/Components/Field.vue';
import Btn from '@/Components/Btn.vue';
import { getJson, postJson } from '@/lib/http';
import { askConfirm } from '@/lib/confirm';
import { toast } from '@/lib/toast';

const props = defineProps({ classes: Array, available: Boolean, reach: Object, history: Array });

const AUDIENCES = [
    { key: 'user', label: 'Satu orang', icon: '👤' },
    { key: 'teachers', label: 'Semua guru', icon: '🧑‍🏫' },
    { key: 'students', label: 'Semua siswa', icon: '🎒' },
    { key: 'class', label: 'Siswa satu rombel', icon: '🏫' },
    { key: 'all', label: 'Semua guru & siswa', icon: '📣' },
];
const audience = ref('students');
const classId = ref(props.classes[0]?.id ?? '');
const role = ref('student');
const q = ref('');
const results = ref([]);
const picked = ref(null);
const form = ref({ title: '', body: '', url: '' });
const errors = ref({});
const sending = ref(false);
const preview = ref(null);

const target = computed(() => (audience.value === 'class' ? classId.value : audience.value === 'user' ? picked.value?.id : null));
const ready = computed(() => form.value.title.trim() && (!['class', 'user'].includes(audience.value) || target.value));

let searchTimer = null;
watch([q, role], () => {
    picked.value = null;
    clearTimeout(searchTimer);
    if (q.value.trim().length < 2) return (results.value = []);
    searchTimer = setTimeout(async () => {
        try { results.value = await getJson(route('admin.push.search', { role: role.value, q: q.value.trim() })); } catch (e) { results.value = []; }
    }, 250);
});

watch([audience, target], async () => {
    preview.value = null;
    if (['class', 'user'].includes(audience.value) && !target.value) return;
    try { preview.value = await postJson(route('admin.push.preview'), { audience: audience.value, target: target.value }); } catch (e) { preview.value = null; }
}, { immediate: true });

const send = async () => {
    errors.value = {};
    const who = AUDIENCES.find((a) => a.key === audience.value).label.toLowerCase();
    const ok = await askConfirm({
        title: 'Kirim pesan ini?',
        message: `"${form.value.title}" akan dikirim ke ${preview.value?.recipients ?? '?'} orang (${who}). Pesan tidak bisa ditarik kembali.`,
        confirmText: 'Kirim sekarang',
    });
    if (!ok) return;
    sending.value = true;
    try {
        const r = await postJson(route('admin.push.store'), { audience: audience.value, target: target.value, title: form.value.title.trim(), body: form.value.body.trim() || null, url: form.value.url.trim() || null });
        toast(`Terkirim ke ${r.recipients} orang${r.with_push ? `, ${r.with_push} lewat push` : ''}.`);
        form.value = { title: '', body: '', url: '' };
        router.reload({ only: ['history', 'reach'] });
    } catch (e) {
        errors.value = Object.fromEntries(Object.entries(e.data?.errors ?? {}).map(([k, v]) => [k, v[0]]));
        toast(e.message, 'error');
    } finally { sending.value = false; }
};
const when = (iso) => new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const pct = (r) => (r.total ? Math.round((r.with_push / r.total) * 100) : 0);
</script>

<template>
    <Head title="Kirim pesan" />
    <AppLayout title="Kirim pesan 📣">
        <div class="grid gap-6 lg:grid-cols-[1fr_24rem]">
            <Card>
                <form class="space-y-5" @submit.prevent="send">
                    <div>
                        <p class="mb-2 text-sm font-bold">Kirim ke</p>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="a in AUDIENCES" :key="a.key" type="button" class="rounded-xl border-2 px-3 py-2 font-bold transition"
                                :class="audience === a.key ? 'border-accent bg-accent text-on-accent' : 'border-line hover:border-accent/60'" :aria-pressed="audience === a.key"
                                :data-testid="`aud-${a.key}`" @click="audience = a.key">{{ a.icon }} {{ a.label }}</button>
                        </div>
                    </div>

                    <Field v-if="audience === 'class'" v-model="classId" as="select" label="Rombel" data-testid="class-select">
                        <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </Field>

                    <div v-if="audience === 'user'" class="space-y-2">
                        <div class="flex gap-2">
                            <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-bold" :class="role === 'student' ? 'bg-surface2' : 'text-muted'" @click="role = 'student'">Siswa</button>
                            <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-bold" :class="role === 'teacher' ? 'bg-surface2' : 'text-muted'" @click="role = 'teacher'">Guru</button>
                        </div>
                        <Field v-model="q" label="Cari nama atau username" placeholder="ketik minimal 2 huruf" data-testid="user-search" />
                        <p v-if="picked" class="rounded-xl bg-success/15 px-3 py-2 text-sm font-bold" data-testid="picked">✅ {{ picked.name }} ({{ picked.username }})</p>
                        <ul v-else-if="results.length" class="divide-y divide-line overflow-hidden rounded-xl border border-line">
                            <li v-for="u in results" :key="u.id"><button type="button" class="block w-full px-3 py-2 text-left hover:bg-surface2" data-testid="user-result" @click="picked = u">{{ u.name }} <span class="text-sm text-muted">· {{ u.username }}</span></button></li>
                        </ul>
                    </div>

                    <Field v-model="form.title" label="Judul" maxlength="100" placeholder="Contoh: Libur besok" :error="errors.title" data-testid="push-title" />
                    <Field v-model="form.body" as="textarea" :rows="3" label="Isi pesan (opsional)" maxlength="300" :error="errors.body" data-testid="push-body" />
                    <Field v-model="form.url" label="Tautan tujuan (opsional)" placeholder="/siswa" :error="errors.url" hint="Halaman di dalam aplikasi yang terbuka saat pesan diketuk. Kosongkan untuk membuka daftar notifikasi." />

                    <div class="rounded-xl bg-surface2 p-3 text-sm" data-testid="preview">
                        <template v-if="preview">Akan diterima <b>{{ preview.recipients }}</b> orang di lonceng, <b>{{ preview.with_push }}</b> di antaranya punya push aktif.</template>
                        <template v-else>Pilih sasaran untuk melihat jumlah penerima.</template>
                        <span v-if="!available" class="mt-1 block font-semibold text-warning">Push belum aktif (kunci VAPID belum diatur atau dimatikan di Pengaturan). Pesan tetap masuk lonceng.</span>
                    </div>
                    <p class="text-xs text-muted">Pesan admin melewati batas harian dan jam tenang, jadi pakai seperlunya.</p>
                    <Btn type="submit" size="lg" :loading="sending" :disabled="!ready || !preview?.recipients" data-testid="push-send">📣 Kirim pesan</Btn>
                </form>
            </Card>

            <aside class="space-y-4">
                <Card>
                    <h2 class="mb-2 text-lg font-bold">Jangkauan push</h2>
                    <div v-for="(r, label) in { Guru: reach.teachers, Siswa: reach.students }" :key="label" class="mb-3">
                        <div class="mb-1 flex justify-between text-sm font-semibold"><span>{{ label }}</span><span>{{ r.with_push }} / {{ r.total }} ({{ pct(r) }}%)</span></div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full bg-accent" :style="{ width: pct(r) + '%' }"></div></div>
                    </div>
                    <p class="text-xs text-muted">Yang belum mengaktifkan push tetap melihat pesan di lonceng. Minta mereka membuka menu 🔔 → Lihat semua → Aktifkan notifikasi.</p>
                </Card>
                <Card>
                    <h2 class="mb-2 text-lg font-bold">Riwayat pengiriman</h2>
                    <p v-if="!history.length" class="text-sm text-muted">Belum ada pesan manual.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="h in history" :key="h.id" class="py-2 text-sm">
                            <p class="font-bold">{{ h.title }}</p>
                            <p class="text-muted">{{ h.target }} · {{ h.recipients }} penerima, {{ h.with_push }} push</p>
                            <p class="text-xs text-muted">{{ when(h.at) }} · {{ h.sender }}</p>
                        </li>
                    </ul>
                </Card>
            </aside>
        </div>
    </AppLayout>
</template>
