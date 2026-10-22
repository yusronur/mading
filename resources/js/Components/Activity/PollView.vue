<script setup>
import { computed, ref } from 'vue';
import { ANSWER_STYLES } from '@/lib/useLive';
import { toast } from '@/lib/toast';
import { beep } from '@/lib/effects';

// Poll langsung dengan grafik batang yang bergerak saat suara masuk (LIV-04).
const props = defineProps({ a: Object, big: Boolean, canVote: Boolean, respond: Function });

const options = computed(() => props.a.config.options);
const results = computed(() => props.a.results ?? null);
const total = computed(() => (results.value ?? []).reduce((s, n) => s + n, 0));
const mine = computed(() => props.a.mine?.[0] ?? null);
const open = computed(() => props.a.status === 'open');
const busy = ref(false);

const pick = async (o) => {
    if (!props.canVote || !open.value || busy.value) return;
    busy.value = true;
    try { await props.respond(o.id); beep('pop'); } catch (e) { toast(e.message, 'error'); } finally { busy.value = false; }
};
const pct = (i) => (total.value ? Math.round(((results.value?.[i] ?? 0) / total.value) * 100) : 0);
</script>

<template>
    <div class="space-y-3">
        <p class="text-center font-display font-bold" :class="big ? 'text-[min(5vh,4vw)] leading-tight' : 'text-2xl'">{{ a.config.question }}</p>
        <ul class="space-y-2.5" :class="big ? 'mx-auto max-w-5xl' : ''">
            <li v-for="(o, i) in options" :key="o.id">
                <button type="button" :disabled="!canVote || !open" class="relative flex w-full items-center gap-3 overflow-hidden rounded-card px-4 text-left font-bold shadow-card transition active:scale-[.99] disabled:cursor-default"
                    :class="[big ? 'min-h-[9vh] text-[min(3.6vh,3vw)]' : 'min-h-14 text-lg', mine === o.id ? 'ring-4 ring-ink ring-offset-2 ring-offset-bg' : '']"
                    :style="{ background: ANSWER_STYLES[i % 6].bg, color: ANSWER_STYLES[i % 6].fg }" :aria-pressed="mine === o.id" data-testid="poll-option" @click="pick(o)">
                    <span v-if="results" class="absolute inset-y-0 left-0 bg-black/20 transition-[width] duration-500" :style="{ width: pct(i) + '%' }"></span>
                    <span class="relative min-w-0 flex-1 break-words">{{ o.text }}<span v-if="mine === o.id" class="ml-2 text-sm">✓ pilihanmu</span></span>
                    <span v-if="results" class="relative shrink-0 font-mono font-black tabular-nums" data-testid="poll-count">{{ results[i] }} <span class="text-sm font-bold opacity-80">({{ pct(i) }}%)</span></span>
                </button>
            </li>
        </ul>
        <p class="text-center text-sm font-semibold text-muted" :class="big ? '!text-2xl' : ''">
            <template v-if="results">{{ total }} suara</template>
            <template v-else>{{ a.responses }} suara masuk · hasil tampil setelah ditutup</template>
            <template v-if="!open"> · poll ditutup</template>
        </p>
    </div>
</template>
