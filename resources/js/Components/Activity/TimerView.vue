<script setup>
import { computed, watch } from 'vue';
import { beep } from '@/lib/effects';
import { confetti } from '@/lib/confetti';

// Timer hitung mundur yang sama di semua layar (jam diselaraskan dengan server).
const props = defineProps({ a: Object, now: Number, big: Boolean });

const total = computed(() => (props.a.config.seconds ?? 60) * 1000);
const endsAt = computed(() => Date.parse(props.a.state?.ends_at));
const paused = computed(() => props.a.status === 'open' && !!props.a.state?.paused_left);
const left = computed(() => (props.a.status !== 'open' ? 0 : paused.value ? props.a.state.paused_left : Math.max(0, endsAt.value - props.now)));
const secs = computed(() => Math.ceil(left.value / 1000));
const label = computed(() => `${String(Math.floor(secs.value / 60)).padStart(2, '0')}:${String(secs.value % 60).padStart(2, '0')}`);
const fraction = computed(() => (props.a.status === 'open' ? Math.min(1, left.value / total.value) : 0));
const done = computed(() => props.a.status === 'open' && !paused.value && left.value === 0);
const stopped = computed(() => props.a.status !== 'open');
const urgent = computed(() => !done.value && !paused.value && secs.value <= 10 && props.a.status === 'open');

// Bunyi hanya untuk timer yang baru berjalan atau baru selesai (bukan yang sudah lama usai saat halaman dibuka)
watch(secs, (s, old) => {
    if (props.a.status !== 'open' || old === undefined || paused.value) return;
    if (s > 0 && s <= 5 && s < old) beep('tick');
    if (s === 0 && old > 0) { beep('end'); confetti({ duration: 1500, count: 60 }); }
});

const R = 90;
const C = 2 * Math.PI * R;
</script>

<template>
    <div class="flex flex-col items-center gap-3 py-2">
        <div class="relative grid place-items-center" :class="big ? 'size-[min(62vh,60vw)]' : 'size-56 sm:size-64'">
            <svg viewBox="0 0 200 200" class="absolute inset-0 -rotate-90" aria-hidden="true">
                <circle cx="100" cy="100" :r="R" fill="none" stroke="var(--m-surface2)" stroke-width="12" />
                <circle cx="100" cy="100" :r="R" fill="none" stroke-width="12" stroke-linecap="round"
                    :stroke="done || urgent ? 'var(--m-danger)' : 'var(--m-accent)'" :stroke-dasharray="C" :stroke-dashoffset="C * (1 - fraction)"
                    class="transition-[stroke-dashoffset] duration-300 ease-linear" />
            </svg>
            <div v-if="paused" class="absolute inset-0 z-10 grid place-items-center rounded-full bg-bg/75 backdrop-blur-[2px]" data-testid="timer-paused" role="status">
                <div class="text-center">
                    <p :class="big ? 'text-[min(16vh,14vw)]' : 'text-7xl'" aria-hidden="true">⏸</p>
                    <p class="font-display font-black tracking-wide text-accent uppercase" :class="big ? 'text-[min(6vh,5vw)]' : 'text-2xl'">Dijeda</p>
                </div>
            </div>
            <div class="text-center">
                <p class="font-display leading-none font-black tabular-nums" :class="[big ? 'text-[min(18vh,16vw)]' : 'text-6xl', done || urgent ? 'text-danger' : '', done ? 'animate-pulse' : '']" data-testid="timer-label">{{ stopped ? '--:--' : label }}</p>
                <p v-if="done" class="mt-1 font-bold text-danger" :class="big ? 'text-4xl' : 'text-xl'">Waktu habis!</p>
                <p v-else-if="stopped" class="mt-1 font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Timer dihentikan</p>
            </div>
        </div>
    </div>
</template>
