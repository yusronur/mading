<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

/** Rekap nilai per rombel: kuis, tugas kuis, live, dan rubrik papan (Tahap 18). */
const props = defineProps({ classes: Array, class_id: String, all: Boolean, is_admin: Boolean, recap: Object });
const go = (extra) => router.get(route('recap.index'), { kelas: props.class_id, guru: props.all ? undefined : props.is_admin ? 'saya' : undefined, ...extra }, { preserveScroll: true });
const kindIcon = { quiz: '📝', live: '⚡', board: '📌' };
const q = ref('');
const rows = computed(() => (props.recap?.rows ?? []).filter((r) => !q.value || r.name.toLowerCase().includes(q.value.toLowerCase())));
const tone = (v, kkm = 75) => (v === null ? 'text-muted' : v >= kkm ? '' : 'text-danger font-bold');
const date = (iso) => (iso ? new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) : '');
const exportUrl = computed(() => route('recap.export', { kelas: props.class_id, guru: props.is_admin && !props.all ? 'saya' : undefined }));
</script>

<template>
    <Head title="Rekap nilai" />
    <AppLayout title="Rekap nilai 📊" wide>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select :value="class_id" class="h-11 rounded-xl border-2 border-line bg-surface px-3 font-bold" aria-label="Rombel" @change="go({ kelas: $event.target.value })">
                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="is_admin" class="flex rounded-xl bg-surface2 p-1" role="tablist">
                <button v-for="p in [[true, 'Semua guru'], [false, 'Milik saya']]" :key="String(p[0])" role="tab" :aria-selected="all === p[0]" class="rounded-lg px-3 py-2 text-sm font-bold"
                    :class="all === p[0] ? 'bg-surface shadow-card' : 'text-muted'" @click="go({ guru: p[0] ? undefined : 'saya' })">{{ p[1] }}</button>
            </div>
            <input v-model="q" type="search" placeholder="Cari siswa" class="h-11 rounded-xl border-2 border-line bg-surface px-3" />
            <a v-if="recap?.columns.length" :href="exportUrl" class="ml-auto inline-flex h-11 items-center rounded-xl bg-accent px-4 font-bold text-on-accent" data-testid="recap-export">⬇️ Unduh Excel</a>
        </div>

        <EmptyState v-if="!class_id" title="Belum ada rombel" text="Rekap muncul setelah admin membuat rombel di tahun ajaran aktif." />
        <EmptyState v-else-if="!recap.columns.length" title="Belum ada nilai"
            text="Nilai muncul di sini dari kuis yang ditugaskan ke rombel ini, kuis live untuk rombel ini, dan papan rombel yang memakai rubrik penilaian." />
        <div v-else class="overflow-x-auto rounded-card border border-line bg-surface shadow-card" data-testid="recap-table">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-surface2 text-left">
                        <th class="sticky left-0 z-10 min-w-44 bg-surface2 px-3 py-2">Nama</th>
                        <th v-for="c in recap.columns" :key="c.key" class="min-w-24 px-2 py-2 align-bottom font-semibold">
                            <component :is="c.url ? 'a' : 'span'" :href="c.url" class="line-clamp-3 hover:underline" :title="`${c.title}${all && c.teacher ? ' · ' + c.teacher : ''}`">{{ kindIcon[c.kind] }} {{ c.title }}</component>
                            <span class="block text-xs font-normal text-muted">{{ date(c.date) }}</span>
                        </th>
                        <th class="min-w-20 px-2 py-2 text-right">Rata-rata</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line">
                        <td class="sticky left-0 z-10 bg-surface px-3 py-2 font-bold">{{ r.name }}</td>
                        <td v-for="c in recap.columns" :key="c.key" class="px-2 py-2 text-center tabular-nums" :class="tone(r.cells[c.key], c.kkm)">{{ r.cells[c.key] ?? '–' }}</td>
                        <td class="px-2 py-2 text-right font-display font-bold tabular-nums" :class="tone(r.average)">{{ r.average ?? '–' }}</td>
                    </tr>
                    <tr class="border-t-2 border-line bg-surface2/60 font-bold">
                        <td class="sticky left-0 z-10 bg-surface2 px-3 py-2">Rata-rata kelas</td>
                        <td v-for="c in recap.columns" :key="c.key" class="px-2 py-2 text-center tabular-nums">{{ recap.column_average[c.key] ?? '–' }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="recap?.columns.length" class="mt-3 text-sm text-muted">Merah = di bawah KKM. Kuis memakai percobaan yang dihitung (terbaik/terakhir sesuai pengaturan). Papan = rata-rata nilai rubrik kartu siswa.</p>
    </AppLayout>
</template>
