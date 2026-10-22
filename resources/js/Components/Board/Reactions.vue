<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

// Reaksi kartu sesuai jenis yang dipilih guru (INT-02).
const props = defineProps({ boardId: String, post: Object, can: Boolean, manage: Boolean });
const r = computed(() => props.post.reaction);
const busy = ref(false);

const send = (value) => {
    if (!props.can || busy.value) return;
    busy.value = true;
    router.post(route('posts.react', [props.boardId, props.post.id]), { value }, {
        preserveScroll: true, preserveState: true, only: ['posts', 'version'],
        onFinish: () => (busy.value = false),
    });
};

const hover = ref(0);
const gradeInput = ref('');
const gradeOpen = ref(false);
const saveGrade = () => {
    const v = gradeInput.value === '' ? null : Math.max(0, Math.min(100, parseInt(gradeInput.value, 10)));
    gradeOpen.value = false;
    send(Number.isNaN(v) ? null : v);
};
</script>

<template>
    <div v-if="r" class="flex items-center gap-1 text-sm font-bold">
        <!-- Suka -->
        <button v-if="r.kind === 'like'" type="button" :disabled="!can" :aria-pressed="r.mine === 1" :aria-label="r.mine ? 'Batal suka' : 'Suka'"
            class="flex h-8 items-center gap-1 rounded-full px-2.5 transition active:scale-90" :class="r.mine ? 'bg-danger/15 text-danger' : 'hover:bg-surface2 text-muted'"
            @click="send(r.mine ? null : 1)">
            <span :class="r.mine ? 'animate-pop' : ''">{{ r.mine ? '❤️' : '🤍' }}</span><span v-if="r.count">{{ r.count }}</span>
        </button>

        <!-- Bintang -->
        <div v-else-if="r.kind === 'star'" class="flex items-center gap-1" @mouseleave="hover = 0">
            <button v-for="n in 5" :key="n" type="button" :disabled="!can" :aria-label="`${n} bintang`"
                class="text-lg leading-none transition active:scale-90" :class="(hover || r.mine || 0) >= n ? '' : 'opacity-30 grayscale'"
                @mouseenter="hover = n" @click="send(r.mine === n ? null : n)">⭐</button>
            <span class="ml-1 text-xs text-muted">{{ r.average ? r.average.toString().replace('.', ',') : '–' }} <span v-if="r.count">({{ r.count }})</span></span>
        </div>

        <!-- Vote -->
        <div v-else-if="r.kind === 'vote'" class="flex items-center rounded-full bg-surface2/70">
            <button type="button" :disabled="!can" aria-label="Setuju" class="grid size-8 place-items-center rounded-full transition active:scale-90" :class="r.mine === 1 ? 'bg-success/20' : 'hover:bg-surface2'" @click="send(r.mine === 1 ? null : 1)">👍</button>
            <span class="min-w-6 text-center" :class="r.score > 0 ? 'text-success' : r.score < 0 ? 'text-danger' : 'text-muted'">{{ r.score }}</span>
            <button type="button" :disabled="!can" aria-label="Tidak setuju" class="grid size-8 place-items-center rounded-full transition active:scale-90" :class="r.mine === -1 ? 'bg-danger/20' : 'hover:bg-surface2'" @click="send(r.mine === -1 ? null : -1)">👎</button>
        </div>

        <!-- Nilai dari guru -->
        <template v-else-if="r.kind === 'grade'">
            <form v-if="manage && gradeOpen" class="flex items-center gap-1" @submit.prevent="saveGrade">
                <input v-model="gradeInput" type="number" min="0" max="100" inputmode="numeric" autofocus placeholder="0-100"
                    class="h-8 w-20 rounded-lg border-2 border-accent bg-surface px-2 text-ink" @keydown.esc="gradeOpen = false" />
                <button class="h-8 rounded-lg bg-accent px-2 text-on-accent">OK</button>
            </form>
            <button v-else-if="manage" type="button" class="flex h-8 items-center gap-1 rounded-full bg-surface2/70 px-3 hover:bg-surface2"
                @click="gradeInput = r.grade ?? ''; gradeOpen = true">💯 {{ r.grade ?? 'Beri nilai' }}</button>
            <span v-else-if="r.grade !== null" class="flex h-8 items-center gap-1 rounded-full bg-accent/15 px-3 text-accent">💯 {{ r.grade }}</span>
        </template>
    </div>
</template>
