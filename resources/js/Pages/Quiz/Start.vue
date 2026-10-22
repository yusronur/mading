<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Card from '@/Components/Card.vue';
import { askConfirm } from '@/lib/confirm';
import { fmtDateTime, untilText } from '@/lib/datetime';

const props = defineProps({ isGuest: Boolean, session: Object, attempts: Array, attemptsLeft: Number, inProgress: String });
const s = computed(() => props.session);
const canStart = computed(() => s.value.status === 'open' && (props.attemptsLeft === null || props.attemptsLeft > 0));

const start = async () => {
    if (!props.inProgress && s.value.duration) {
        const ok = await askConfirm({
            title: 'Mulai sekarang?',
            message: `Waktumu ${s.value.duration} menit, dihitung sejak kamu menekan Mulai dan tetap berjalan walaupun halaman ditutup. Tiap percobaan mendapat waktu penuh yang baru.`,
            confirmText: 'Mulai 🚀',
        });
        if (!ok) return;
    }
    router.post(route('take.start', s.value.id));
};
</script>

<template>
    <Head :title="s.title" />
    <AppLayout :back="isGuest ? null : { href: route('student.dashboard'), label: 'Beranda' }">
        <Card class="mx-auto max-w-xl text-center">
            <p class="text-5xl">📝</p>
            <h1 class="mt-2 text-3xl font-bold">{{ s.title }}</h1>
            <p class="text-muted">dari {{ s.teacher }}</p>
            <p v-if="s.description" class="mt-3 whitespace-pre-line">{{ s.description }}</p>

            <dl class="mt-5 grid grid-cols-2 gap-2 text-left sm:grid-cols-4">
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Soal</dt><dd class="font-display text-xl font-bold">{{ s.questions }}</dd></div>
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Waktu</dt><dd class="font-display text-xl font-bold">{{ s.duration ? s.duration + ' mnt' : 'Bebas' }}</dd></div>
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Kesempatan</dt><dd class="font-display text-xl font-bold">{{ s.attempts === 0 ? '∞' : s.attempts + '×' }}</dd></div>
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Tenggat</dt><dd class="text-sm font-bold">{{ fmtDateTime(s.closes_at) }}</dd></div>
            </dl>

            <p v-if="s.status === 'scheduled'" class="mt-5 rounded-xl bg-warning/15 px-4 py-3 font-bold text-warning">Kuis dibuka {{ fmtDateTime(s.opens_at) }}.</p>
            <p v-else-if="s.status === 'closed'" class="mt-5 rounded-xl bg-surface2 px-4 py-3 font-bold text-muted">Kuis sudah ditutup.</p>
            <p v-else-if="s.closes_at" class="mt-4 text-sm text-muted">Ditutup {{ untilText(s.closes_at) }}.</p>

            <div class="mt-6 flex flex-col gap-2">
                <Btn v-if="inProgress" size="lg" block :href="route('take.attempt', inProgress)">Lanjutkan mengerjakan →</Btn>
                <Btn v-else-if="canStart" size="lg" block @click="start">{{ attempts.length ? 'Kerjakan lagi' : 'Mulai mengerjakan' }} 🚀</Btn>
                <p v-else-if="attemptsLeft === 0" class="font-semibold text-muted">Kesempatanmu sudah habis.</p>
            </div>

            <div v-if="attempts.some((a) => a.submitted)" class="mt-6 border-t border-line pt-4 text-left">
                <p class="mb-2 font-bold">Pekerjaanmu</p>
                <ul class="space-y-2">
                    <li v-for="a in attempts.filter((x) => x.submitted)" :key="a.id">
                        <Link :href="route('take.result', a.id)" class="flex items-center justify-between rounded-xl bg-surface2 px-4 py-3 hover:bg-line/60">
                            <span>Percobaan {{ a.no }} · <span class="text-sm text-muted">{{ fmtDateTime(a.submitted_at) }}</span></span>
                            <span class="font-display text-xl font-bold">{{ a.grade ?? '✓' }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </Card>
    </AppLayout>
</template>
