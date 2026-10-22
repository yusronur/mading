<script setup>
import { computed, ref } from 'vue';

/**
 * Menjodohkan: cocokkan tiap butir kiri dengan satu kartu jawaban.
 *
 * Dua cara, satu logika:
 *  - Ketuk kartu lalu ketuk kotak tujuan (atau sebaliknya). Dipakai di HP dan
 *    layar sentuh, tanpa seret sehingga layar tidak ikut bergeser.
 *  - Seret-lepas dengan mouse. Dimatikan di layar sentuh (pointer: coarse).
 * Nilai: { idKiri: idKanan }.
 */
const props = defineProps({ lefts: Array, rights: Array, modelValue: { type: Object, default: () => ({}) }, readonly: Boolean });
const emit = defineEmits(['update:modelValue']);

const coarse = typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches;
const rightMap = computed(() => Object.fromEntries(props.rights.map((r) => [r.id, r])));
const value = computed(() => props.modelValue || {});
const used = computed(() => new Set(Object.values(value.value)));
const bank = computed(() => props.rights.filter((r) => !used.value.has(r.id)));
const filled = computed(() => Object.keys(value.value).length);

const pickedChip = ref(null);
const pickedSlot = ref(null);
const dragging = ref(null);
const hoverSlot = ref(null);
const hoverBank = ref(false);

const commit = (map) => emit('update:modelValue', map);
const without = (map, chipId) => {
    for (const k of Object.keys(map)) if (map[k] === chipId) delete map[k];
    return map;
};
const place = (leftId, chipId) => {
    // Kartu yang tadinya di kotak lain pindah; kartu lama di kotak ini kembali ke bank
    commit({ ...without({ ...value.value }, chipId), [leftId]: chipId });
    pickedChip.value = pickedSlot.value = null;
};
const release = (leftId) => {
    const map = { ...value.value };
    delete map[leftId];
    commit(map);
};

// ---------- Ketuk
const tapChip = (id) => {
    if (props.readonly) return;
    if (pickedSlot.value) return place(pickedSlot.value, id);
    pickedChip.value = pickedChip.value === id ? null : id;
};
const tapSlot = (leftId) => {
    if (props.readonly) return;
    if (pickedChip.value) return place(leftId, pickedChip.value);
    if (value.value[leftId]) return release(leftId);
    pickedSlot.value = pickedSlot.value === leftId ? null : leftId;
};

// ---------- Seret (mouse)
const dragStart = (e, id) => {
    dragging.value = id;
    e.dataTransfer.setData('text/plain', id);
    e.dataTransfer.effectAllowed = 'move';
};
const dragEnd = () => { dragging.value = null; hoverSlot.value = null; hoverBank.value = false; };
const droppedId = (e) => dragging.value || e.dataTransfer.getData('text/plain');
const dropSlot = (e, leftId) => {
    const id = droppedId(e);
    dragEnd();
    if (id && rightMap.value[id]) place(leftId, id);
};
const dropBank = (e) => {
    const id = droppedId(e);
    dragEnd();
    if (id && rightMap.value[id]) commit(without({ ...value.value }, id));
};
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm font-semibold text-muted">
            <template v-if="coarse">Ketuk satu kartu jawaban, lalu ketuk kotak tujuannya. Ketuk kotak yang sudah terisi untuk mengosongkannya.</template>
            <template v-else>Seret kartu jawaban ke kotak yang sesuai, atau ketuk kartu lalu ketuk kotaknya. Seret kembali ke bawah untuk melepas.</template>
        </p>

        <ul class="space-y-2.5">
            <li v-for="(l, i) in lefts" :key="l.id" class="grid items-stretch gap-2 sm:grid-cols-[1fr_auto_1fr]">
                <div class="flex items-center gap-3 rounded-2xl border-2 border-line bg-surface p-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-sm font-bold text-bg">{{ i + 1 }}</span>
                    <span class="min-w-0 flex-1 text-base font-semibold break-words sm:text-lg">{{ l.text }}</span>
                </div>
                <span class="hidden place-items-center text-2xl text-muted sm:grid" aria-hidden="true">⟷</span>
                <div role="button" tabindex="0" class="flex min-h-14 items-center rounded-2xl border-2 px-3 py-2 text-left transition select-none"
                    :class="[
                        value[l.id] ? 'border-accent bg-accent/10' : 'border-dashed border-line bg-surface2/50',
                        pickedSlot === l.id ? 'ring-2 ring-accent3' : '',
                        pickedChip && !readonly ? 'cursor-pointer hover:border-accent' : '',
                        hoverSlot === l.id ? 'scale-[1.02] border-accent bg-accent/20' : '',
                    ]"
                    :aria-label="value[l.id] ? `Jodoh nomor ${i + 1}: ${rightMap[value[l.id]]?.text}. Ketuk untuk mengosongkan` : `Kotak jawaban nomor ${i + 1}, kosong`"
                    @click="tapSlot(l.id)" @keydown.enter.prevent="tapSlot(l.id)" @keydown.space.prevent="tapSlot(l.id)"
                    @dragover.prevent="hoverSlot = l.id" @dragleave="hoverSlot === l.id && (hoverSlot = null)" @drop.prevent="dropSlot($event, l.id)">
                    <span v-if="value[l.id]" class="flex w-full items-center gap-2" :draggable="!coarse && !readonly" @dragstart="dragStart($event, value[l.id])" @dragend="dragEnd">
                        <span class="min-w-0 flex-1 font-semibold break-words">{{ rightMap[value[l.id]]?.text }}</span>
                        <span v-if="!readonly" class="text-muted" aria-hidden="true">✕</span>
                    </span>
                    <span v-else class="text-sm text-muted">{{ pickedChip ? 'Ketuk di sini untuk menaruh' : coarse ? 'Pilih jawaban lalu ketuk di sini' : 'Taruh jawaban di sini' }}</span>
                </div>
            </li>
        </ul>

        <section aria-label="Kartu jawaban" class="rounded-2xl border-2 border-dashed p-3 transition" :class="hoverBank ? 'border-accent bg-accent/10' : 'border-line'"
            @dragover.prevent="hoverBank = true" @dragleave="hoverBank = false" @drop.prevent="dropBank">
            <p class="mb-2 text-xs font-bold text-muted uppercase">Pilihan jawaban ({{ bank.length }} tersisa)</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="r in bank" :key="r.id" type="button" :draggable="!coarse && !readonly"
                    class="min-h-12 rounded-xl border-2 bg-surface px-4 py-2 text-left font-semibold break-words shadow-card transition active:scale-95"
                    :class="[pickedChip === r.id ? 'border-accent ring-2 ring-accent bg-accent/15' : 'border-line hover:border-accent/60', !coarse && !readonly ? 'cursor-grab active:cursor-grabbing' : '', dragging === r.id ? 'opacity-40' : '']"
                    :aria-pressed="pickedChip === r.id" @click="tapChip(r.id)" @dragstart="dragStart($event, r.id)" @dragend="dragEnd">{{ r.text }}</button>
                <p v-if="!bank.length" class="text-sm text-muted">{{ filled === lefts.length ? 'Semua pasangan sudah terisi ✓' : 'Semua kartu sudah dipakai.' }}</p>
            </div>
        </section>
    </div>
</template>
