<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ANSWER_STYLES } from '@/lib/useLive';
import { beep } from '@/lib/effects';
import { confetti } from '@/lib/confetti';

// Roda pengacak nama (LIV-02). Pemenang diundi di server; roda hanya menganimasikan
// hasilnya, jadi semua layar mendarat di nama yang sama.
const props = defineProps({
    names: Array, spin: Object, picked: { type: Array, default: () => [] }, noRepeat: { type: Boolean, default: true },
    serverNow: Number, big: Boolean,
});

const SPIN_MS = 5600;
const START = 22; // jarak awal teks dari pusat roda

// Nama yang sudah terpilih dikeluarkan dari roda (mode tanpa pengulangan). Pemenang putaran
// yang sedang berjalan tetap ada sampai animasi selesai, lalu hilang dari roda.
const settled = ref(true);
const visible = computed(() => {
    const out = props.noRepeat ? new Set(props.picked) : new Set();
    const cur = props.spin?.index;
    return props.names.map((name, idx) => ({ name, idx })).filter((v) => !out.has(v.idx) || (!settled.value && v.idx === cur));
});
const n = computed(() => visible.value.length);
const slice = computed(() => 360 / Math.max(1, n.value));
// Mode tanpa pengulangan: sisa satu nama tidak perlu diputar, otomatis terpilih
const lastLeft = computed(() => (props.noRepeat && settled.value && n.value === 1 ? visible.value[0].name : null));
const showLabels = computed(() => n.value <= 60);
const baseFont = computed(() => Math.max(4.5, Math.min(12, 330 / Math.max(1, n.value))));

const polar = (deg, r) => {
    const a = ((deg - 90) * Math.PI) / 180;
    return [Math.cos(a) * r, Math.sin(a) * r];
};
const slices = computed(() => visible.value.map((v, i) => {
    const a0 = i * slice.value;
    const a1 = (i + 1) * slice.value;
    const [x0, y0] = polar(a0, 96);
    const [x1, y1] = polar(a1, 96);
    const st = ANSWER_STYLES[v.idx % ANSWER_STYLES.length];
    // Ukuran huruf menyesuaikan panjang nama agar muat di dalam irisan
    const room = 96 - START - 4;
    const font = Math.max(4.5, Math.min(baseFont.value, room / (Math.max(1, v.name.length) * 0.6)));
    const maxChars = Math.floor(room / (font * 0.6) + 0.01);
    return {
        i, name: v.name, mid: a0 + slice.value / 2, fill: st.bg, fg: st.fg, font,
        d: n.value === 1 ? '' : `M0 0 L${x0.toFixed(2)} ${y0.toFixed(2)} A96 96 0 ${slice.value > 180 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z`,
        label: v.name.length > maxChars ? v.name.slice(0, Math.max(1, maxChars - 1)) + '…' : v.name,
    };
}));

// Sudut roda: irisan pemenang harus berhenti di bawah penunjuk (atas)
const rotation = ref(0);
const animate = ref(false);
const winner = ref(null);
let timer = null;
let settleTimer = null;
let lastSpin = null;

const jitter = (id) => {
    let h = 0;
    for (const c of id) h = (h * 31 + c.charCodeAt(0)) | 0;
    return ((Math.abs(h) % 70) / 100 - 0.35) * slice.value;
};
const target = (pos, id) => -(pos + 0.5) * slice.value + jitter(id);

watch(() => props.spin?.id, (id) => {
    clearTimeout(timer);
    clearTimeout(settleTimer);
    if (!id) { winner.value = null; animate.value = false; rotation.value = 0; lastSpin = null; settled.value = true; return; }
    const age = props.serverNow - Date.parse(props.spin.at);
    const idx = props.spin.index;

    if (age > 12000 || lastSpin === null && age > SPIN_MS) {
        // Halaman dibuka sesudah putaran selesai: tampilkan hasilnya saja
        settled.value = true;
        animate.value = false;
        rotation.value = 0;
        winner.value = props.names[idx];
    } else {
        settled.value = false;
        winner.value = null;
        const pos = Math.max(0, visible.value.findIndex((v) => v.idx === idx));
        const base = Math.ceil(rotation.value / 360) * 360;
        animate.value = true;
        rotation.value = base + 360 * 6 + target(pos, id);
        const wait = Math.max(300, SPIN_MS - Math.max(0, age));
        timer = setTimeout(() => {
            winner.value = props.names[idx];
            beep('win');
            confetti({ duration: 2600, count: 120 });
            // Setelah dirayakan, nama pemenang keluar dari roda dan roda kembali ke posisi awal
            settleTimer = setTimeout(() => { settled.value = true; animate.value = false; rotation.value = 0; }, 2800);
        }, wait);
    }
    lastSpin = id;
}, { immediate: true });
onBeforeUnmount(() => { clearTimeout(timer); clearTimeout(settleTimer); });
</script>

<template>
    <div class="flex flex-col items-center gap-4">
        <div class="relative" :class="big ? 'size-[min(66vh,70vw)]' : 'size-72 sm:size-80'">
            <!-- Penunjuk -->
            <svg viewBox="0 0 24 24" class="absolute -top-3 left-1/2 z-10 w-9 -translate-x-1/2 drop-shadow" aria-hidden="true"><path d="M12 22 2 4h20z" fill="var(--m-text)" stroke="var(--m-bg)" stroke-width="2" stroke-linejoin="round" /></svg>
            <p v-if="!n" class="absolute inset-0 z-10 grid place-items-center p-6 text-center font-display text-xl font-bold text-muted">Semua nama sudah terpilih 🎉</p>
            <div v-else-if="lastLeft" class="absolute inset-0 z-10 grid place-items-center rounded-full bg-surface2 p-8 text-center" data-testid="wheel-last">
                <div>
                    <p class="font-display font-black text-accent" :class="big ? 'text-[min(8vh,7vw)]' : 'text-3xl'">🎉 {{ lastLeft }}</p>
                    <p class="mt-2 font-bold text-muted" :class="big ? 'text-2xl' : 'text-sm'">Nama terakhir, otomatis terpilih</p>
                </div>
            </div>
            <svg v-show="n && !lastLeft" viewBox="-100 -100 200 200" class="size-full" role="img" aria-label="Roda pengacak nama">
                <g :style="{ transform: `rotate(${rotation}deg)`, transition: animate ? `transform ${SPIN_MS}ms cubic-bezier(.12,.72,.08,1)` : 'none' }">
                    <circle r="98" fill="var(--m-surface2)" />
                    <g v-for="s in slices" :key="s.i">
                        <path :d="s.d" :fill="s.fill" stroke="var(--m-bg)" stroke-width="0.8" />
                        <text v-if="showLabels" :transform="`rotate(${s.mid - 90}) translate(${START} 0)`" :fill="s.fg" :font-size="s.font" font-weight="800" dominant-baseline="central">{{ s.label }}</text>
                    </g>
                    <circle r="9" fill="var(--m-bg)" stroke="var(--m-border)" stroke-width="1.5" />
                </g>
            </svg>
        </div>
        <div class="min-h-14 text-center" aria-live="polite">
            <p v-if="winner" class="animate-pop font-display font-black text-accent" :class="big ? 'text-[min(9vh,8vw)]' : 'text-4xl'" data-testid="wheel-winner">🎉 {{ winner }}</p>
            <p v-else-if="lastLeft" class="font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Semua nama sudah terpilih</p>
            <p v-else-if="spin" class="animate-pulse font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Berputar…</p>
            <p v-else class="font-bold text-muted" :class="big ? 'text-3xl' : 'text-lg'">Siap diputar</p>
        </div>
    </div>
</template>
