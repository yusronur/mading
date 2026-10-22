<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { postJson } from '@/lib/http';
import { confetti } from '@/lib/confetti';
import { refreshGamify } from '@/lib/gamify';

// Menampilkan hadiah (XP, level, lencana, streak) yang tercatat di server sejak terakhir dilihat.
const page = usePage();
const shown = new Set();
const items = reactive([]);
const notices = computed(() => page.props.gamify?.notices ?? []);

function label(n) {
    if (n.type === 'xp') return { icon: '✨', text: `+${n.points} XP`, sub: n.label };
    if (n.type === 'level') return { icon: '🚀', text: `Naik ke level ${n.level}!`, sub: n.title };
    if (n.type === 'badge') return { icon: n.icon, text: 'Lencana baru!', sub: n.name };
    return { icon: '🔥', text: `Rentetan ${n.days} hari`, sub: 'Terus semangat!' };
}

async function ack(ids) {
    try { await postJson(route('gamify.seen'), { ids }); } catch { /* akan tampil lagi nanti */ }
}

watch(notices, (list) => {
    const fresh = list.filter((n) => !shown.has(n.id));
    if (!fresh.length) return;
    fresh.forEach((n) => shown.add(n.id));
    ack(fresh.map((n) => n.id));
    // Gabungkan XP beruntun menjadi satu agar layar tidak penuh
    const xp = fresh.filter((n) => n.type === 'xp');
    const rest = fresh.filter((n) => n.type !== 'xp');
    const queue = [];
    if (xp.length) queue.push({ type: 'xp', points: xp.reduce((a, n) => a + n.points, 0), label: xp.length === 1 ? xp[0].label : `${xp.length} aktivitas` });
    queue.push(...rest);
    queue.forEach((n, i) => setTimeout(() => {
        const id = n.id ?? `${Date.now()}-${i}`;
        items.push({ id, ...label(n), tone: n.type });
        setTimeout(() => { const k = items.findIndex((x) => x.id === id); if (k >= 0) items.splice(k, 1); }, n.type === 'xp' ? 2800 : 4500);
        if ((n.type === 'level' || n.type === 'badge')) confetti({ duration: 1800, count: 80 });
    }, i * 700));
}, { immediate: true, deep: true });

// Aksi di halaman tanpa navigasi (komentar, kuis live) tetap tampil hadiahnya
let timer;
const poke = () => { if (document.visibilityState === 'visible') refreshGamify(); };
onMounted(() => { document.addEventListener('visibilitychange', poke); timer = setInterval(poke, 90000); });
onBeforeUnmount(() => { document.removeEventListener('visibilitychange', poke); clearInterval(timer); });
</script>

<template>
    <div class="pointer-events-none fixed inset-x-4 bottom-24 z-[55] mx-auto flex max-w-sm flex-col items-center gap-2 lg:bottom-6" aria-live="polite" data-testid="rewards">
        <TransitionGroup enter-from-class="opacity-0 translate-y-3 scale-95" leave-to-class="opacity-0 translate-y-3"
            enter-active-class="transition duration-300" leave-active-class="transition duration-300">
            <div v-for="i in items" :key="i.id" role="status"
                class="flex w-full items-center gap-3 rounded-2xl border-2 px-4 py-3 shadow-card"
                :class="i.tone === 'xp' ? 'border-line bg-surface' : 'border-accent bg-surface'">
                <span class="text-3xl" aria-hidden="true">{{ i.icon }}</span>
                <div class="min-w-0">
                    <p class="font-display text-lg leading-tight font-bold">{{ i.text }}</p>
                    <p class="truncate text-sm text-muted">{{ i.sub }}</p>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
