<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Btn from '@/Components/Btn.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import BoardCard from '@/Components/BoardCard.vue';
import { Link } from '@inertiajs/vue3';
import LevelBar from '@/Components/Gamify/LevelBar.vue';
import { untilText } from '@/lib/datetime';

defineProps({ boards: Array, className: String, quizzes: { type: Array, default: () => [] } });
const page = usePage();
const gamify = computed(() => usePage().props.gamify);
const firstName = (page.props.auth.user?.name || '').split(' ')[0];
const join = useForm({ code: '' });
const submit = () => join.post(route('boards.join'));
</script>

<template>
    <Head title="Beranda" />
    <AppLayout>
        <section class="mb-6">
            <p class="font-semibold text-muted">{{ className ? `Kelas ${className}` : 'Belum terdaftar di rombel' }}</p>
            <h1 class="text-4xl font-bold sm:text-5xl">Hai, {{ firstName }}! ✨</h1>
        </section>

        <Link v-if="gamify" :href="route('gamify.me')" class="mb-6 block" data-testid="xp-card">
            <Card class="transition hover:-translate-y-0.5">
                <LevelBar :g="gamify" />
                <p v-if="gamify.streak > 0" class="mt-2 text-sm font-bold">🔥 Rentetan {{ gamify.streak }} hari</p>
            </Card>
        </Link>

        <Card class="mb-8 bg-gradient-to-br from-accent/20 via-surface to-accent2/15">
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submit">
                <div class="flex-1">
                    <label for="code" class="mb-1 block font-display text-lg font-bold">Punya kode papan atau kuis dari guru?</label>
                    <input id="code" v-model="join.code" maxlength="6" placeholder="ABC123" autocomplete="off" autocapitalize="characters"
                        class="h-14 w-full rounded-xl border-2 border-line bg-surface px-4 text-center font-mono text-2xl font-bold tracking-[0.4em] text-ink uppercase placeholder:tracking-[0.3em] placeholder:text-muted/50 focus:border-accent focus:outline-none" />
                    <p v-if="join.errors.code" class="mt-1 text-sm font-semibold text-danger">{{ join.errors.code }}</p>
                </div>
                <Btn type="submit" size="lg" :loading="join.processing" :disabled="join.code.length !== 6">Gabung →</Btn>
            </form>
        </Card>

        <template v-if="quizzes.length">
            <h2 class="mb-3 text-2xl font-bold">Kuis untukmu 📝</h2>
            <div class="mb-8 grid gap-3 sm:grid-cols-2">
                <Link v-for="q in quizzes" :key="q.id" :href="route('take.show', q.id)"
                    class="flex items-center gap-4 rounded-card border-2 bg-surface p-4 shadow-card transition hover:-translate-y-0.5"
                    :class="q.status === 'open' && q.mine !== 'done' ? 'border-accent' : 'border-line opacity-80'">
                    <span class="grid size-14 shrink-0 place-items-center rounded-2xl font-display text-xl font-bold"
                        :class="q.mine === 'done' ? 'bg-success text-bg' : q.mine === 'working' ? 'bg-warning text-bg' : 'bg-accent text-on-accent'">
                        {{ q.mine === 'done' ? (q.grade ?? '✓') : q.mine === 'working' ? '…' : '!' }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-lg font-bold">{{ q.title }}</span>
                        <span class="block text-sm text-muted">{{ q.questions }} soal{{ q.duration ? ` · ${q.duration} menit` : '' }} · {{ q.teacher }}</span>
                        <span class="block text-sm font-semibold" :class="q.status === 'open' && q.mine !== 'done' ? 'text-accent' : 'text-muted'">
                            {{ q.mine === 'done' ? 'Selesai' : q.mine === 'working' ? 'Lanjutkan mengerjakan' : q.status === 'closed' ? 'Ditutup' : 'Tenggat ' + untilText(q.closes_at) }}
                        </span>
                    </span>
                </Link>
            </div>
        </template>

        <h2 class="mb-3 text-2xl font-bold">Papan untukmu</h2>
        <EmptyState v-if="!boards.length" title="Belum ada papan" text="Kalau gurumu sudah membuat papan untuk kelasmu, papan itu akan muncul di sini." />
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <BoardCard v-for="(b, i) in boards" :key="b.id" :board="b" :index="i" />
        </div>
    </AppLayout>
</template>
