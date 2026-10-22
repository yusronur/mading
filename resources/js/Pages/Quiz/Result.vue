<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Card from '@/Components/Card.vue';
import MediaViewer from '@/Components/Board/MediaViewer.vue';
import MathHtml from '@/Components/Quiz/MathHtml.vue';
import { fmtDateTime } from '@/lib/datetime';

// Hasil & pembahasan (siswa), atau lembar jawaban siswa (guru).
const props = defineProps({ session: Object, attempt: Object, review: Array, canReview: Boolean, teacherView: Boolean });
const a = computed(() => props.attempt);
const viewing = ref(null);
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const kkm = computed(() => props.session.kkm ?? props.session.settings?.kkm ?? 75);
const mood = computed(() => (a.value.grade === null ? '🎉' : a.value.grade >= 90 ? '🏆' : a.value.grade >= kkm.value ? '🎉' : '💪'));
const chosen = (r, o) => (Array.isArray(r.response) ? r.response.includes(o.id) : r.response === o.id);
const isKey = (r, o) => r.key.includes(o.id);
const pairGiven = (r, j) => {
    const id = r.response?.[r.lefts[j].id];
    return id ? r.rights.find((x) => x.id === id)?.text : null;
};
const pairOk = (r, j) => pairGiven(r, j) !== null && pairGiven(r, j) === r.key[j].right;
const correctCount = computed(() => props.review.filter((r) => r.correct).length);
</script>

<template>
    <Head :title="`Hasil ${session.title}`" />
    <AppLayout :back="teacherView ? { href: route('assignments.show', session.id), label: 'Laporan kuis' } : { href: route('take.show', session.id), label: 'halaman kuis' }">

        <Card class="mb-6 text-center">
            <p v-if="teacherView" class="font-bold text-muted">{{ a.student }} · percobaan {{ a.attempt_no }}</p>
            <p class="text-6xl">{{ mood }}</p>
            <h1 class="mt-1 text-2xl font-bold">{{ teacherView ? session.title : 'Jawaban terkumpul!' }}</h1>
            <p v-if="a.grade !== null" class="mt-3 font-display text-6xl font-bold" :class="a.grade >= kkm ? 'text-success' : 'text-warning'">{{ a.grade }}</p>
            <p v-if="a.score !== null" class="text-muted">Skor {{ a.score }} dari {{ a.max_score }}<span v-if="review.length"> · {{ correctCount }}/{{ review.length }} benar</span></p>
            <p v-else class="text-muted">{{ a.answered }} dari {{ a.total }} soal dijawab. Nilai akan disampaikan guru.</p>
            <p class="mt-1 text-sm text-muted">Dikumpulkan {{ fmtDateTime(a.submitted_at) }}</p>
            <p v-if="!canReview && !teacherView" class="mt-4 rounded-xl bg-surface2 px-4 py-3 text-sm font-semibold">
                {{ session.review === 'after_close' ? 'Pembahasan bisa dilihat setelah kuis ditutup.' : 'Guru tidak menampilkan pembahasan untuk kuis ini.' }}
            </p>
        </Card>

        <ol v-if="canReview" class="space-y-3">
            <li v-for="(r, i) in review" :key="r.id" class="rounded-card border-2 bg-surface p-4" :class="r.correct ? 'border-success/50' : 'border-danger/40'">
                <p class="mb-2 flex items-center gap-2 text-sm font-bold">
                    <span class="grid size-7 place-items-center rounded-full text-bg" :class="r.correct ? 'bg-success' : 'bg-danger'">{{ r.correct ? '✓' : '✗' }}</span>
                    Soal {{ i + 1 }} <span class="font-normal text-muted">· {{ r.earned }}/{{ r.points }} poin</span>
                </p>
                <MathHtml :html="r.prompt" class="rich" />
                <div v-if="r.media.length" class="mt-2 flex flex-wrap gap-2">
                    <template v-for="m in r.media" :key="m.id">
                        <button v-if="m.kind === 'image'" type="button" @click="viewing = m"><img :src="m.thumb_url || m.url" :alt="m.name" class="h-24 rounded-lg object-cover" /></button>
                        <audio v-else-if="m.kind === 'audio'" :src="m.url" controls></audio>
                    </template>
                </div>
                <ul v-if="r.options" class="mt-3 grid gap-1.5 sm:grid-cols-2">
                    <li v-for="(o, j) in r.options" :key="o.id" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm"
                        :class="isKey(r, o) ? 'bg-success/15 font-bold text-success' : chosen(r, o) ? 'bg-danger/15 text-danger' : 'bg-surface2/60'">
                        <span class="font-display font-bold">{{ r.type === 'tf' ? '' : LETTERS[j] + '.' }}</span>
                        <MathHtml :html="o.text" plain tag="span" class="flex-1" />
                        <span v-if="chosen(r, o)" class="text-xs font-bold">{{ teacherView ? 'dipilih' : 'pilihanmu' }}</span>
                    </li>
                </ul>
                <ul v-else-if="r.type === 'match'" class="mt-3 space-y-1.5 text-sm">
                    <li v-for="(k, j) in r.key" :key="j" class="rounded-xl px-3 py-2" :class="pairOk(r, j) ? 'bg-success/15' : 'bg-danger/10'">
                        <span class="font-bold">{{ j + 1 }}. {{ k.left }}</span>
                        <span class="mx-1 text-muted">⟷</span>
                        <span :class="pairOk(r, j) ? 'font-bold text-success' : 'text-danger line-through'">{{ pairGiven(r, j) || '(kosong)' }}</span>
                        <span v-if="!pairOk(r, j)" class="ml-1 font-bold text-success">→ {{ k.right }}</span>
                    </li>
                </ul>
                <div v-else class="mt-3 space-y-1 text-sm">
                    <p :class="r.correct ? 'text-success' : 'text-danger'"><b>{{ teacherView ? 'Jawaban siswa' : 'Jawabanmu' }}:</b> {{ r.response || '(kosong)' }}</p>
                    <p class="text-success"><b>Kunci:</b> {{ r.key.join(' / ') }}</p>
                </div>
                <div v-if="r.explanation" class="mt-3 rounded-xl bg-surface2/70 px-4 py-3 text-sm"><p class="mb-1 font-bold">💡 Pembahasan</p><MathHtml :html="r.explanation" class="rich" /></div>
            </li>
        </ol>
        <MediaViewer :item="viewing" @close="viewing = null" />
    </AppLayout>
</template>
