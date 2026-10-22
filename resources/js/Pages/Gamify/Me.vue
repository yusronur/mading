<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import LevelBar from '@/Components/Gamify/LevelBar.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ summary: Object, badges: Array, history: Array, rules: Array, daily_cap: Number });
const when = (iso) => new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Prestasi" />
    <AppLayout title="Prestasiku 🏅">
        <Card class="mb-6 bg-gradient-to-br from-accent/20 via-surface to-accent2/15">
            <LevelBar :g="summary" big />
            <div class="mt-4 flex flex-wrap gap-3 text-sm font-bold">
                <span class="rounded-full bg-surface2 px-3 py-1" data-testid="streak">🔥 Rentetan {{ summary.streak }} hari</span>
                <span class="rounded-full bg-surface2 px-3 py-1">Terbaik {{ summary.best_streak }} hari</span>
                <Link :href="route('gamify.leaderboard')" class="rounded-full bg-accent px-3 py-1 text-on-accent">Lihat peringkat kelas →</Link>
            </div>
        </Card>

        <h2 class="mb-3 text-2xl font-bold">Lencana</h2>
        <div class="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div v-for="b in badges" :key="b.key" class="rounded-2xl border-2 p-3 text-center" :class="b.earned_at ? 'border-accent bg-surface' : 'border-line bg-surface2/60'" :data-testid="`badge-${b.key}`" :data-earned="!!b.earned_at">
                <div class="text-4xl" :class="b.earned_at ? '' : 'opacity-30 grayscale'">{{ b.icon }}</div>
                <p class="mt-1 font-display font-bold leading-tight">{{ b.name }}</p>
                <p class="mt-1 text-xs text-muted">{{ b.text }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h2 class="mb-3 text-2xl font-bold">Riwayat XP</h2>
                <Card v-if="history.length" class="divide-y divide-line !p-0">
                    <div v-for="(h, i) in history" :key="i" class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0"><p class="truncate font-semibold">{{ h.label }}</p><p class="text-xs text-muted">{{ when(h.at) }}</p></div>
                        <span class="font-display text-lg font-bold text-accent">+{{ h.points }}</span>
                    </div>
                </Card>
                <p v-else class="text-muted">Belum ada XP. Kirim kartu, beri komentar, atau ikut kuis untuk memulai!</p>
            </div>
            <div>
                <h2 class="mb-3 text-2xl font-bold">Cara dapat XP</h2>
                <Card class="divide-y divide-line !p-0">
                    <div v-for="r in rules" :key="r.label" class="flex justify-between px-4 py-3"><span class="font-semibold">{{ r.label }}</span><span class="font-bold text-muted">sampai {{ r.points + (r.label.includes('kuis') && !r.label.includes('Juara') ? 20 : 0) }} XP</span></div>
                </Card>
                <p class="mt-2 text-sm text-muted">Batas {{ daily_cap }} XP per hari supaya adil. Komentar harus bermakna dan di kartu temanmu.</p>
            </div>
        </div>
    </AppLayout>
</template>
