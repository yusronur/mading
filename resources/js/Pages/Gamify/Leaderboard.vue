<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Btn from '@/Components/Btn.vue';
import Avatar from '@/Components/Avatar.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({ classes: Array, class_id: String, period: String, board: Object, hidden: Boolean, can_toggle: Boolean, can_filter: Boolean, mine: Boolean, me_id: String });
const go = (extra) => router.get(route('gamify.leaderboard'), { kelas: props.class_id, periode: props.period, sumber: props.mine ? 'saya' : undefined, ...extra }, { preserveScroll: true, preserveState: true });
const isStaff = props.can_toggle || props.can_filter;
const medal = (r) => ({ 1: '🥇', 2: '🥈', 3: '🥉' })[r] ?? r;
const current = () => props.classes.find((c) => c.id === props.class_id);
const toggle = () => router.post(route('gamify.toggle', props.class_id), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Peringkat" />
    <AppLayout title="Peringkat kelas 🏆">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="flex rounded-xl bg-surface2 p-1" role="tablist">
                <button v-for="p in [['minggu', 'Minggu ini'], ['total', 'Sepanjang waktu']]" :key="p[0]" role="tab" :aria-selected="period === p[0]"
                    class="rounded-lg px-4 py-2 text-sm font-bold" :class="period === p[0] ? 'bg-surface shadow-card' : 'text-muted'" @click="go({ periode: p[0] })">{{ p[1] }}</button>
            </div>
            <div v-if="can_filter" class="flex rounded-xl bg-surface2 p-1" role="tablist">
                <button v-for="p in [[false, 'Semua XP'], [true, 'XP dari kuis & papan saya']]" :key="String(p[0])" role="tab" :aria-selected="mine === p[0]" :data-testid="p[0] ? 'filter-mine' : 'filter-all'"
                    class="rounded-lg px-4 py-2 text-sm font-bold" :class="mine === p[0] ? 'bg-surface shadow-card' : 'text-muted'" @click="go({ sumber: p[0] ? 'saya' : undefined })">{{ p[1] }}</button>
            </div>
            <select v-if="isStaff && classes.length" :value="class_id" class="h-11 rounded-xl border-2 border-line bg-surface px-3 font-bold" @change="go({ kelas: $event.target.value })">
                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <Btn v-if="can_toggle && class_id" variant="soft" class="ml-auto" data-testid="toggle-leaderboard" @click="toggle">
                {{ current()?.leaderboard ? '👁️ Tampil ke siswa — klik untuk sembunyikan' : '🙈 Disembunyikan dari siswa — klik untuk tampilkan' }}
            </Btn>
        </div>

        <EmptyState v-if="!class_id" title="Belum ada kelas" text="Peringkat muncul setelah kamu terdaftar di sebuah rombel." />
        <EmptyState v-else-if="hidden" title="Peringkat disembunyikan" text="Guru menyembunyikan peringkat kelas ini. Tetap semangat kumpulkan XP!" />
        <template v-else-if="board">
            <p v-if="!board.rows.some((r) => r.xp > 0)" class="mb-4 text-muted">Belum ada XP {{ period === 'minggu' ? 'minggu ini' : '' }}. Jadilah yang pertama!</p>
            <Card class="divide-y divide-line !p-0" data-testid="leaderboard">
                <div v-for="r in board.rows" :key="r.user_id" class="flex items-center gap-3 px-4 py-3" :class="r.user_id === me_id ? 'bg-accent/15' : ''" :data-testid="`lb-${r.rank ?? 'none'}`">
                    <span class="w-9 text-center font-display text-xl font-bold">{{ r.rank ? medal(r.rank) : '–' }}</span>
                    <Avatar :avatar="r.avatar" :frame="r.frame" :initials="r.name.slice(0, 1)" :size="34" />
                    <span class="min-w-0 flex-1 truncate font-bold">{{ r.name }} <span v-if="r.user_id === me_id" class="text-sm text-muted">(kamu)</span></span>
                    <span class="rounded-full bg-surface2 px-2 py-0.5 text-xs font-bold">Lv {{ r.level }}</span>
                    <span class="w-20 text-right font-display font-bold">{{ r.xp }} XP</span>
                </div>
            </Card>
            <Card v-if="board.me && !board.rows.some((r) => r.user_id === me_id)" class="mt-3 border-2 border-accent">
                <div class="flex items-center gap-3"><span class="w-9 text-center font-display text-xl font-bold">{{ board.me.rank ?? '–' }}</span><span class="flex-1 font-bold">Kamu</span><span class="font-display font-bold">{{ board.me.xp }} XP</span></div>
            </Card>
            <p class="mt-3 text-sm text-muted">Peringkat minggu dihitung sejak Senin. {{ board.members }} siswa di kelas ini.</p>
        </template>
    </AppLayout>
</template>
