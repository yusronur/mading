<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Badge from '@/Components/Badge.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import AssignDialog from '@/Components/Quiz/AssignDialog.vue';
import QuizShareDialog from '@/Components/Quiz/QuizShareDialog.vue';
import { askConfirm } from '@/lib/confirm';
import { fmtDateTime, untilText } from '@/lib/datetime';

defineProps({ quizzes: Array, classes: Array });

const newOpen = ref(false);
const nf = useForm({ title: '' });
const create = () => nf.post(route('quizzes.store'));

const assigning = ref(null);
const sharing = ref(null);
const duplicate = (q) => router.post(route('quizzes.duplicate', q.id));
const remove = async (q) => {
    if (await askConfirm({ title: `Hapus kuis "${q.title}"?`, confirmText: 'Hapus', tone: 'danger' })) router.delete(route('quizzes.destroy', q.id), { preserveScroll: true });
};
const statusTone = { open: 'success', scheduled: 'warning', closed: 'neutral' };
const statusLabel = { open: 'Berjalan', scheduled: 'Terjadwal', closed: 'Ditutup' };
</script>

<template>
    <Head title="Kuis" />
    <AppLayout title="Kuis mandiri 📝" wide>
        <template #actions>
            <Btn variant="soft" :href="route('banks.index')">📚 Bank soal</Btn>
            <Btn @click="newOpen = true">+ Kuis baru</Btn>
        </template>

        <EmptyState v-if="!quizzes.length" title="Belum ada kuis" text="Susun kuis dari bank soal, atur waktu dan kesempatan, lalu tugaskan ke rombel. Nilai langsung terhitung otomatis.">
            <Btn @click="newOpen = true">Buat kuis pertama</Btn>
        </EmptyState>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <article v-for="q in quizzes" :key="q.id" class="rounded-card border border-line bg-surface p-5 shadow-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-xl leading-tight font-bold">{{ q.title }}</h3>
                        <p class="mt-1 text-sm text-muted">
                            {{ q.questions_count }} soal
                            · {{ q.settings.duration_minutes + ' menit' }}
                            · {{ q.settings.attempts === 0 ? 'kesempatan tak terbatas' : q.settings.attempts + '× kesempatan' }}
                            · KKM {{ q.settings.kkm }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Btn size="sm" variant="soft" :href="route('quizzes.edit', q.id)">Ubah</Btn>
                        <Btn size="sm" :disabled="!q.questions_count" @click="assigning = q">Tugaskan</Btn>
                    </div>
                </div>

                <ul v-if="q.sessions.length" class="mt-4 space-y-2">
                    <li v-for="s in q.sessions" :key="s.id">
                        <Link :href="route('assignments.show', s.id)" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-surface2/70 px-3 py-2 hover:bg-surface2">
                            <span class="font-bold">{{ s.class_name ?? '–' }}</span>
                            <Badge :tone="statusTone[s.status]">{{ statusLabel[s.status] }}</Badge>
                            <span class="text-sm text-muted">{{ s.done }}/{{ s.students }} selesai</span>
                            <span class="ml-auto text-xs text-muted">{{ s.status === 'open' ? 'tenggat ' + untilText(s.closes_at) : fmtDateTime(s.closes_at) }} </span>
                            <button type="button" class="rounded-lg border border-dashed border-line px-2 py-0.5 font-mono text-sm font-bold tracking-widest hover:border-accent" title="Bagikan kode dan QR" @click.prevent.stop="sharing = s">{{ s.code }} 📤</button>
                        </Link>
                    </li>
                </ul>
                <p v-else class="mt-4 text-sm text-muted">Belum ditugaskan.</p>

                <div class="mt-3 flex gap-3 text-sm font-bold">
                    <button class="text-muted hover:text-ink" @click="duplicate(q)">Duplikat</button>
                    <button class="text-muted hover:text-danger" @click="remove(q)">Hapus</button>
                </div>
            </article>
        </div>

        <Modal :show="newOpen" title="Kuis baru" @close="newOpen = false">
            <form class="space-y-4" @submit.prevent="create">
                <Field v-model="nf.title" label="Judul kuis" placeholder="mis. Ulangan Harian Bab 2 — Sel" maxlength="150" :error="nf.errors.title" />
                <Btn type="submit" block :loading="nf.processing">Lanjut pilih soal →</Btn>
            </form>
        </Modal>
        <QuizShareDialog :show="!!sharing" :session="sharing" @close="sharing = null" />
        <AssignDialog :show="!!assigning" :quiz="assigning" :classes="classes" @close="assigning = null" />
    </AppLayout>
</template>
