<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ANSWER_STYLES } from '@/lib/useLive';
import { beep } from '@/lib/effects';
import { confetti } from '@/lib/confetti';

// Roda pengacak nama (LIV-02). Pemenang diundi di server; roda hanya menganimasikan
// hasilnya, jadi semua layar mendarat di nama yang sama.
const props = defineProps({ names: Array, spin: Object, serverNow: Number, big: Boolean });

const SPIN_MS = 5600;
const n = computed(() => props.names.length);
const slice = computed(() => 360 / n.value);
const showLabels = computed(() => n.value <= 36);

const polar = (deg, r) => {
    const a = ((deg - 90) * Math.PI) / 180;
    return [Math.cos(a) * r, Math.sin(a) * r];
};
const slices = computed(() => props.names.map((name, i) => {
    const a0 = i * slice.value;
    const a1 = (i + 1) * slice.value;
    const [x0, y0] = polar(a0, 96);
    const [x1, y1] = polar(a1, 96);
    const mid = a0 + slice.value / 2;
    const st = ANSWER_STYLES[i % ANSWER_STYLES.length];
    return {
        i, name, mid, fill: st.bg, fg: st.fg,
        d: n.value === 1 ? '' : `M0 0 L${x0.toFixed(2)} ${y0.toFixed(2)} A96 96 0 ${slice.value > 180 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z`,
        label: name.length > 15 ? name.slice(0, 14) + '…' : name,
    };
}));
const fontSize = computed(() => Math.max(3.4, Math.min(9, 200 / n.value)));

// Sudut roda: indeks pemenang harus berhenti di bawah penunjuk (atas)
const rotation = ref(0);
const animate = ref(false);
const winner = ref(null);
let timer = null;
let lastSpin = null;

const jitter = (id) => {
    let h = 0;
    for (const c of id) h = (h * 31 + c.charCodeAt(0)) | 0;
    return ((Math.abs(h) % 70) / 100 - 0.35) * slice.value;
};
const target = (idx, id) => -(idx + 0.5) * slice.value + jitter(id);

watch(() => props.spin?.id, (id) => {
    clearTimeout(timer);
    if (!id) { winner.value = null; animate.value = false; rotation.value = 0; lastSpin = null; return; }
    const age = props.serverNow - Date.parse(props.spin.at);
    const idx = props.spin.index;
    const base = Math.ceil(rotation.value / 360) * 360;

    if (age > 12000 || lastSpin === null && age > SPIN_MS) {
        // Halaman dibuka sesudah putaran selesai: tampilkan hasilnya saja
        animate.value = false;
        rotation.value = target(idx, id) + 360 * 5;
        winner.value = props.names[idx];
    } else {
        winner.value = null;
        animate.value = true;
        rotation.value = base + 360 * 6 + target(idx, id);
        const wait = Math.max(300, SPIN_MS - Math.max(0, age));
        timer = setTimeout(() => { winner.value = props.names[idx]; beep('win'); confetti({ duration: 2600, count: 120 }); }, wait);
    }
    lastSpin = id;
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const pickedNames = computed(() => (props.spin ? [props.names[props.spin.index]] : []));
defineExpose({ pickedNames });
</script>

<template>
    <div class="flex flex-col items-center gap-4">
        <div class="relative" :class="big ? 'size-[min(66vh,70vw)]' : 'size-72 sm:size-80'">
            <!-- Penunjuk -->
            <svg viewBox="0 0 24 24" class="absolute -top-3 left-1/2 z-10 w-9 -translate-x-1/2 drop-shadow" aria-hidden="true"><path d="M12 22 2 4h20z" fill="var(--m-text)" stroke="var(--m-bg)" stroke-width="2" stroke-linejoin="round" /></svg>
            <svg viewBox="-100 -100 200 200" class="size-full" role="img" aria-label="Roda pengacak nama">
                <g :style="{ transform: `rotate(${rotation}deg)`, transition: animate ? `transform ${SPIN_MS}ms cubic-bezier(.12,.72,.08,1)` : 'none' }">
                    <circle r="98" fill="var(--m-surface2)" />
                    <g v-for="s in slices" :key="s.i">
                        <path :d="s.d" :fill="s.fill" stroke="var(--m-bg)" stroke-width="0.8" />
                        <text v-if="showLabels" :transform="`rotate(${s.mid - 90}) translate(30 0)`" :fill="s.fg" :font-size="fontSize" font-weight="800" dominant-baseline="central">{{ s.label }}</text>
                    </g>
                    <circle r="9" fill="var(--m-bg)" stroke="var(--m-border)" stroke-width="1.5" />
                </g>
            </svg>
        </div>
        <div class="min-h-14 text-center" aria-live="polite">
            <p v-if="winner" class="animate-pop font-display font-black text-accent" :class="big ? 'text-[min(9vh,8vw)]' : 'text-4xl'" data-testid="wheel-winner">🎉 {{ winner }}</p>
            <p v-else-if="spin" class="animate-pulse font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Berputar…</p>
            <p v-else class="font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Siap diputar</p>
        </div>
    </div>
</template>
