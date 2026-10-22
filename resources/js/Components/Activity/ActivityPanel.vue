<script setup>
import { computed, ref } from 'vue';
import Btn from '@/Components/Btn.vue';
import TimerView from '@/Components/Activity/TimerView.vue';
import PollView from '@/Components/Activity/PollView.vue';
import WordCloud from '@/Components/Activity/WordCloud.vue';
import SpinWheel from '@/Components/Activity/SpinWheel.vue';
import { useActivity } from '@/lib/activity';
import { toast } from '@/lib/toast';
import { askConfirm } from '@/lib/confirm';
import { beep } from '@/lib/effects';

// Aktivitas live yang sedang berjalan di papan. Dipakai di halaman papan dan layar proyektor.
const props = defineProps({ boardId: String, initial: Object, channel: String, big: Boolean, canInteract: { type: Boolean, default: true } });
const { activity: a, now, respond, act } = useActivity(props.boardId, props.initial, props.channel);

const ICONS = { timer: '⏱️', poll: '📊', cloud: '☁️', wheel: '🎡' };
const NAMES = { timer: 'Timer', poll: 'Poll', cloud: 'Word cloud', wheel: 'Spin wheel' };
const manage = computed(() => a.value?.can_manage);
const open = computed(() => a.value?.status === 'open');

const run = async (action, extra) => {
    try { await act(action, extra); } catch (e) { toast(e.message, 'error'); }
};
const hide = async () => {
    if (await askConfirm({ title: 'Sembunyikan aktivitas?', message: 'Aktivitas hilang dari layar semua orang. Hasilnya tidak disimpan.', confirmText: 'Sembunyikan', tone: 'danger' })) run('end');
};
const removeWord = (text) => run('remove_word', { text });
const restart = (seconds) => run('restart', seconds ? { seconds } : {});

// Word cloud: isian siswa
const draft = ref('');
const sending = ref(false);
const mine = computed(() => a.value?.mine ?? []);
const left = computed(() => (a.value?.config?.max_words ?? 3) - mine.value.length);
const addWord = async () => {
    const w = draft.value.trim();
    if (!w || sending.value) return;
    sending.value = true;
    try { await respond([w]); draft.value = ''; beep('pop'); } catch (e) { toast(e.message, 'error'); } finally { sending.value = false; }
};
const vote = (id) => respond(id);
const serverNow = computed(() => now.value);
// Daftar "sudah terpilih" menunggu putaran selesai agar tidak membocorkan pemenang
const winnerNames = computed(() => {
    if (a.value?.type !== 'wheel') return [];
    const spin = a.value.state?.spin;
    const spinning = spin && Date.parse(spin.at) + 5800 > now.value;
    return (a.value.state?.picked ?? []).filter((i) => !(spinning && i === spin.index)).map((i) => a.value.config.names[i]);
});
</script>

<template>
    <section v-if="a" class="rounded-card border border-line bg-surface shadow-card" :class="big ? 'p-[2vh]' : 'mb-5 p-4 sm:p-5'" data-testid="activity-panel" data-no-export>
        <header class="mb-3 flex flex-wrap items-center gap-2">
            <span class="text-2xl" aria-hidden="true">{{ ICONS[a.type] }}</span>
            <h2 class="min-w-0 flex-1 truncate font-display font-bold" :class="big ? 'text-3xl' : 'text-xl'">{{ a.title || NAMES[a.type] }}</h2>
            <span v-if="!open && a.type !== 'wheel'" class="rounded-full bg-surface2 px-3 py-0.5 text-xs font-bold text-muted">{{ a.type === 'timer' ? 'dihentikan' : 'ditutup' }}</span>

            <template v-if="manage">
                <Btn v-if="a.type === 'timer'" size="sm" variant="soft" @click="restart()">↻ Mulai ulang</Btn>
                <Btn v-if="a.type === 'timer' && open" size="sm" variant="soft" @click="run('close')">■ Hentikan</Btn>
                <Btn v-if="(a.type === 'poll' || a.type === 'cloud') && open" size="sm" variant="soft" @click="run('close')">Tutup {{ a.type === 'poll' ? 'poll' : 'jawaban' }}</Btn>
                <Btn v-if="(a.type === 'poll' || a.type === 'cloud') && !open" size="sm" variant="soft" @click="run('reopen')">Buka lagi</Btn>
                <Btn size="sm" variant="ghost" @click="hide">✕ Sembunyikan</Btn>
            </template>
        </header>

        <TimerView v-if="a.type === 'timer'" :a="a" :now="now" :big="big" />

        <PollView v-else-if="a.type === 'poll'" :a="a" :big="big" :can-vote="canInteract" :respond="vote" />

        <div v-else-if="a.type === 'cloud'">
            <p class="text-center font-display font-bold" :class="big ? 'text-[min(5vh,4vw)] leading-tight' : 'text-2xl'">{{ a.config.prompt }}</p>
            <WordCloud :words="a.words" :big="big" :removable="manage" @remove="removeWord" />
            <form v-if="open && canInteract" class="mx-auto mt-2 flex max-w-lg gap-2" @submit.prevent="addWord">
                <input v-model="draft" maxlength="40" autocomplete="off" :disabled="left <= 0" :placeholder="left > 0 ? 'Ketik satu kata atau frasa pendek' : 'Batas kata tercapai'" aria-label="Kata kamu"
                    class="h-12 min-w-0 flex-1 rounded-xl border-2 border-line bg-surface px-4 text-ink focus:border-accent focus:outline-none disabled:opacity-60" data-testid="cloud-input" />
                <Btn type="submit" :loading="sending" :disabled="left <= 0 || !draft.trim()">Kirim</Btn>
            </form>
            <p class="mt-2 text-center text-sm font-semibold text-muted" :class="big ? '!text-xl' : ''">
                {{ a.responses }} kata masuk<template v-if="open && canInteract"> · sisa jatahmu {{ Math.max(0, left) }}</template>
                <template v-if="mine.length"> · kata kamu: {{ mine.join(', ') }}</template>
            </p>
        </div>

        <div v-else-if="a.type === 'wheel'">
            <SpinWheel :names="a.config.names" :spin="a.state?.spin" :server-now="serverNow" :big="big" />
            <div v-if="manage" class="mt-2 flex flex-wrap justify-center gap-2">
                <Btn size="lg" data-testid="wheel-spin" @click="run('spin')">🎡 Putar!</Btn>
                <Btn variant="soft" @click="run('reset_wheel')">Atur ulang</Btn>
            </div>
            <p v-if="winnerNames.length" class="mt-3 text-center text-sm text-muted" :class="big ? '!text-xl' : ''">Sudah terpilih: {{ winnerNames.join(', ') }}</p>
        </div>
    </section>
</template>
