<script setup>
import { computed, ref, watch } from 'vue';
import { postJson, putJson, deleteJson } from '@/lib/http';
import { toast } from '@/lib/toast';
import { askConfirm } from '@/lib/confirm';
import QuickChips from '@/Components/Board/QuickChips.vue';

/** Nilai rubrik kartu: guru mengisi, penulis kartu melihat hasil dan umpan balik (Tahap 18). */
const props = defineProps({ post: Object, board: Object, manage: Boolean, quick: Array });
const emit = defineEmits(['quick-update']);

const rubric = computed(() => props.board.rubric ?? []);
const max = computed(() => rubric.value.reduce((n, c) => n + c.max, 0));
const scores = ref([]);
const feedback = ref('');
const saving = ref(false);
const grade = ref(props.post.grade);

const reset = () => {
    grade.value = props.post.grade;
    scores.value = rubric.value.map((_, i) => props.post.grade?.scores?.[i] ?? '');
    feedback.value = props.post.grade?.feedback ?? '';
};
watch(() => [props.post.id, props.post.grade?.updated_at], reset, { immediate: true });

const total = computed(() => scores.value.reduce((n, v) => n + (Number(v) || 0), 0));
const preview = computed(() => (max.value ? Math.round((total.value / max.value) * 100) : 0));
const complete = computed(() => scores.value.every((v) => v !== '' && v !== null));

const save = async () => {
    if (!complete.value) return toast('Isi skor semua kriteria.', 'error');
    saving.value = true;
    try {
        const res = await putJson(route('posts.grade', [props.board.id, props.post.id]), { scores: scores.value.map(Number), feedback: feedback.value });
        grade.value = res.grade;
        toast(`Nilai ${res.grade.grade} disimpan.`);
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        saving.value = false;
    }
};
const clear = async () => {
    if (!(await askConfirm({ title: 'Hapus nilai kartu ini?', confirmText: 'Hapus nilai', tone: 'danger' }))) return;
    try {
        await deleteJson(route('posts.ungrade', [props.board.id, props.post.id]));
        grade.value = null;
        scores.value = rubric.value.map(() => '');
        feedback.value = '';
    } catch (e) { toast(e.message, 'error'); }
};
const addFeedback = (t) => (feedback.value = feedback.value ? `${feedback.value.trim()} ${t}` : t);
</script>

<template>
    <!-- Guru: form nilai -->
    <section v-if="manage && rubric.length && !post.is_teacher" class="space-y-3 rounded-2xl border-2 border-accent/40 p-4" data-testid="grade-panel">
        <div class="flex items-center justify-between">
            <h3 class="font-bold">📊 Nilai rubrik</h3>
            <span class="font-display text-2xl font-bold" :class="complete ? '' : 'text-muted'">{{ preview }}<span class="text-sm text-muted">/100</span></span>
        </div>
        <div v-for="(c, i) in rubric" :key="i" class="flex items-center gap-3">
            <label :for="`sc-${i}`" class="min-w-0 flex-1 text-sm font-semibold">{{ c.name }}</label>
            <input :id="`sc-${i}`" v-model="scores[i]" type="number" inputmode="decimal" min="0" :max="c.max" step="0.5" :data-testid="`score-${i}`"
                class="h-10 w-20 rounded-xl border-2 border-line bg-surface px-2 text-right font-bold text-ink" />
            <span class="w-10 text-sm text-muted">/ {{ c.max }}</span>
        </div>
        <textarea v-model="feedback" rows="2" maxlength="2000" placeholder="Umpan balik untuk siswa (opsional)" aria-label="Umpan balik"
            class="w-full rounded-xl border-2 border-line bg-surface px-3 py-2 text-ink"></textarea>
        <QuickChips :items="quick" @pick="addFeedback" @update="emit('quick-update', $event)" />
        <div class="flex items-center justify-between gap-2">
            <button v-if="grade" type="button" class="text-sm font-bold text-muted hover:text-danger" @click="clear">Hapus nilai</button>
            <span v-else></span>
            <button type="button" class="h-10 rounded-xl bg-accent px-4 font-bold text-on-accent disabled:opacity-50" :disabled="saving" data-testid="grade-save" @click="save">{{ grade ? 'Perbarui nilai' : 'Simpan nilai' }}</button>
        </div>
        <p v-if="grade" class="text-xs text-muted">Terakhir dinilai {{ grade.grader }}.</p>
    </section>

    <!-- Siswa penulis: hasil -->
    <section v-else-if="grade && !manage" class="rounded-2xl border-2 border-success/50 bg-success/10 p-4" data-testid="grade-result">
        <div class="flex items-center justify-between">
            <h3 class="font-bold">📊 Nilai dari guru</h3>
            <span class="font-display text-3xl font-bold">{{ grade.grade }}</span>
        </div>
        <ul class="mt-2 space-y-1 text-sm">
            <li v-for="(c, i) in rubric" :key="i" class="flex justify-between"><span>{{ c.name }}</span><span class="font-bold">{{ grade.scores[i] }} / {{ c.max }}</span></li>
        </ul>
        <p v-if="grade.feedback" class="mt-3 rounded-xl bg-surface px-3 py-2 text-sm whitespace-pre-line">💬 {{ grade.feedback }}</p>
    </section>
</template>
