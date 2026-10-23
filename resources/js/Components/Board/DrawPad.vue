<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';

/** Papan coret sederhana (Tahap 17): hasilnya PNG yang diunggah sebagai lampiran gambar. */
const emit = defineEmits(['done', 'cancel']);
const W = 1200;
const H = 800;
const COLORS = ['#1f2937', '#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#a855f7', '#ec4899'];
const SIZES = [4, 10, 22];

const canvas = ref(null);
const color = ref(COLORS[0]);
const size = ref(SIZES[1]);
const eraser = ref(false);
const strokes = ref([]); // [{ color, size, points: [[x,y]...] }]
let ctx = null;
let current = null;

const point = (e) => {
    const r = canvas.value.getBoundingClientRect();
    return [((e.clientX - r.left) / r.width) * W, ((e.clientY - r.top) / r.height) * H];
};

const drawStroke = (s) => {
    ctx.strokeStyle = s.color;
    ctx.fillStyle = s.color;
    ctx.lineWidth = s.size;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    const p = s.points;
    if (p.length === 1) {
        ctx.beginPath();
        ctx.arc(p[0][0], p[0][1], s.size / 2, 0, Math.PI * 2);
        ctx.fill();
        return;
    }
    ctx.beginPath();
    ctx.moveTo(p[0][0], p[0][1]);
    // kurva halus lewat titik tengah antartitik
    for (let i = 1; i < p.length - 1; i++) {
        const mx = (p[i][0] + p[i + 1][0]) / 2;
        const my = (p[i][1] + p[i + 1][1]) / 2;
        ctx.quadraticCurveTo(p[i][0], p[i][1], mx, my);
    }
    ctx.lineTo(p[p.length - 1][0], p[p.length - 1][1]);
    ctx.stroke();
};

const redraw = () => {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    strokes.value.forEach(drawStroke);
};

const down = (e) => {
    e.preventDefault();
    canvas.value.setPointerCapture?.(e.pointerId);
    current = { color: eraser.value ? '#ffffff' : color.value, size: eraser.value ? size.value * 2 : size.value, points: [point(e)] };
    strokes.value.push(current);
    drawStroke(current);
};
const move = (e) => {
    if (!current) return;
    current.points.push(point(e));
    redraw();
};
const up = () => (current = null);

const undo = () => { strokes.value.pop(); redraw(); };
const clear = () => { strokes.value = []; redraw(); };

const done = () => {
    if (!strokes.value.length) return emit('cancel');
    canvas.value.toBlob((blob) => {
        if (blob) emit('done', new File([blob], `coretan-${Date.now()}.png`, { type: 'image/png' }));
    }, 'image/png');
};

onMounted(() => {
    ctx = canvas.value.getContext('2d');
    redraw();
});
onBeforeUnmount(() => (current = null));
</script>

<template>
    <div class="space-y-2 rounded-xl border-2 border-line bg-surface2 p-2" data-testid="draw-pad">
        <div class="flex flex-wrap items-center gap-1.5">
            <button v-for="c in COLORS" :key="c" type="button" class="size-7 rounded-full border-2" :class="!eraser && color === c ? 'border-ink scale-110' : 'border-transparent'"
                :style="{ background: c }" :aria-label="`Warna ${c}`" @click="color = c; eraser = false"></button>
            <span class="mx-1 h-6 w-px bg-line"></span>
            <button v-for="s in SIZES" :key="s" type="button" class="grid size-8 place-items-center rounded-lg" :class="size === s ? 'bg-surface shadow-card' : ''" :aria-label="`Tebal ${s}`" @click="size = s">
                <span class="rounded-full bg-ink" :style="{ width: Math.max(4, s / 1.6) + 'px', height: Math.max(4, s / 1.6) + 'px' }"></span>
            </button>
            <button type="button" class="h-8 rounded-lg px-2 text-sm font-bold" :class="eraser ? 'bg-surface shadow-card' : ''" @click="eraser = !eraser">🧽 Hapus</button>
            <button type="button" class="h-8 rounded-lg px-2 text-sm font-bold disabled:opacity-40" :disabled="!strokes.length" @click="undo">↶ Urungkan</button>
            <button type="button" class="h-8 rounded-lg px-2 text-sm font-bold disabled:opacity-40" :disabled="!strokes.length" @click="clear">Bersihkan</button>
        </div>
        <canvas ref="canvas" :width="W" :height="H" class="block aspect-[3/2] w-full touch-none rounded-lg bg-white" style="cursor: crosshair"
            aria-label="Area menggambar" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @pointerleave="up"></canvas>
        <div class="flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl px-3 text-sm font-bold hover:bg-line/60" @click="emit('cancel')">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-accent px-4 text-sm font-bold text-on-accent disabled:opacity-40" :disabled="!strokes.length" data-testid="draw-done" @click="done">✓ Pakai gambar</button>
        </div>
    </div>
</template>
