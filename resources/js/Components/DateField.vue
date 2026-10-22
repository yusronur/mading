<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Pemilih tanggal (dan jam) berupa kalender buatan sendiri, bukan pemilih bawaan
 * peramban, supaya tampilannya sama di semua perangkat dan ikut tema.
 *
 * v-model: "YYYY-MM-DD" (mode date) atau "YYYY-MM-DDTHH:mm" (mode datetime).
 * Kalender dibuka sebagai popover; di layar sempit menjadi lembar dari bawah.
 */
const props = defineProps({
    label: String,
    error: String,
    hint: String,
    mode: { type: String, default: 'date' }, // date | datetime
    required: Boolean,
    min: String, // "YYYY-MM-DD"
    placeholder: { type: String, default: 'Pilih tanggal' },
    quick: { type: Array, default: () => ['07:00', '12:00', '15:00', '23:59'] }, // jam cepat (datetime)
});
const model = defineModel({ type: String, default: '' });
const id = useId();

const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const p2 = (n) => String(n).padStart(2, '0');
const iso = (y, m, d) => `${y}-${p2(m + 1)}-${p2(d)}`;

const parse = (v) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(v || '');
    return m ? { y: +m[1], m: +m[2] - 1, d: +m[3], h: m[4] !== undefined ? +m[4] : 0, i: m[5] !== undefined ? +m[5] : 0 } : null;
};
const cur = computed(() => parse(model.value));
const isDT = computed(() => props.mode === 'datetime');

const display = computed(() => {
    const c = cur.value;
    if (!c) return '';
    const dt = new Date(c.y, c.m, c.d);
    const day = dt.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    return isDT.value ? `${day} · ${p2(c.h)}.${p2(c.i)}` : day;
});

// ---------- Popover
const open = ref(false);
const btn = ref(null);
const pop = ref(null);
const pos = ref({ top: 0, left: 0, sheet: false, center: false });
const view = ref({ y: 0, m: 0 });
const mode = ref('days'); // days | months

const place = () => {
    const sheet = window.innerWidth < 640;
    if (sheet || !btn.value) return (pos.value = { top: 0, left: 0, sheet: true, center: false });
    const r = btn.value.getBoundingClientRect();
    const w = 320;
    const h = pop.value?.offsetHeight || 440;
    const left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
    const below = window.innerHeight - r.bottom - 12;
    const above = r.top - 12;
    // Muat di bawah tombol, atau di atasnya; bila tidak muat keduanya (layar pendek),
    // tampil di tengah layar dengan latar redup agar tidak menimpa tombolnya
    if (below >= h) return (pos.value = { top: r.bottom + 8, left, sheet: false, center: false });
    if (above >= h) return (pos.value = { top: r.top - h - 8, left, sheet: false, center: false });
    pos.value = { top: Math.max(8, (window.innerHeight - h) / 2), left: Math.max(8, (window.innerWidth - w) / 2), sheet: false, center: true };
};
const show = async () => {
    const c = cur.value ?? (() => { const n = new Date(); return { y: n.getFullYear(), m: n.getMonth(), d: n.getDate(), h: 23, i: 59 }; })();
    view.value = { y: c.y, m: c.m };
    mode.value = 'days';
    open.value = true;
    await nextTick();
    place();
};
const hide = () => { open.value = false; btn.value?.focus(); };
const onDoc = (e) => { if (open.value && !pop.value?.contains(e.target) && !btn.value?.contains(e.target)) open.value = false; };
const onKey = (e) => { if (open.value && e.key === 'Escape') { e.stopPropagation(); hide(); } };
watch(open, (v) => {
    const fn = v ? 'addEventListener' : 'removeEventListener';
    document[fn]('mousedown', onDoc);
    document[fn]('keydown', onKey, true);
    window[fn]('resize', place);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDoc);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', place);
});

// ---------- Kalender
const todayIso = (() => { const n = new Date(); return iso(n.getFullYear(), n.getMonth(), n.getDate()); })();
const cells = computed(() => {
    const { y, m } = view.value;
    const first = new Date(y, m, 1);
    const lead = (first.getDay() + 6) % 7; // Senin = 0
    const total = new Date(y, m + 1, 0).getDate();
    const out = [];
    for (let k = 0; k < 42; k++) {
        const d = new Date(y, m, 1 - lead + k);
        const v = iso(d.getFullYear(), d.getMonth(), d.getDate());
        out.push({ v, day: d.getDate(), other: d.getMonth() !== m, off: !!props.min && v < props.min });
    }
    return total ? out : [];
});
const selectedDate = computed(() => (cur.value ? iso(cur.value.y, cur.value.m, cur.value.d) : null));
const shiftMonth = (n) => {
    const d = new Date(view.value.y, view.value.m + n, 1);
    view.value = { y: d.getFullYear(), m: d.getMonth() };
};

const time = computed(() => ({ h: cur.value?.h ?? 23, i: cur.value?.i ?? 59 }));
const write = (date, h = time.value.h, i = time.value.i) => {
    model.value = isDT.value ? `${date}T${p2(h)}:${p2(i)}` : date;
};
const pick = (c) => {
    if (c.off) return;
    write(c.v);
    if (!isDT.value) hide();
    else if (c.other) view.value = { y: +c.v.slice(0, 4), m: +c.v.slice(5, 7) - 1 };
};
const setTime = (h, i) => write(selectedDate.value ?? todayIso, ((h % 24) + 24) % 24, ((i % 60) + 60) % 60);
const step = (what, n) => (what === 'h' ? setTime(time.value.h + n, time.value.i) : setTime(time.value.h, time.value.i + n));
const setQuick = (q) => { const [h, i] = q.split(':').map(Number); setTime(h, i); };
const today = () => { write(todayIso); const n = new Date(); view.value = { y: n.getFullYear(), m: n.getMonth() }; if (!isDT.value) hide(); };
const clear = () => { model.value = ''; hide(); };
const years = computed(() => Array.from({ length: 12 }, (_, k) => view.value.y - 4 + k));
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-bold text-ink">{{ label }}</label>
        <button :id="id" ref="btn" type="button" aria-haspopup="dialog" :aria-expanded="open"
            class="flex h-12 w-full items-center gap-2 rounded-xl border-2 bg-surface px-4 text-left transition focus:border-accent focus:outline-none"
            :class="[open ? 'border-accent' : 'border-line', error ? '!border-danger' : '']" @click="open ? hide() : show()">
            <span aria-hidden="true">📅</span>
            <span class="min-w-0 flex-1 truncate" :class="display ? 'text-ink' : 'text-muted/70'">{{ display || placeholder }}</span>
            <span class="text-muted" aria-hidden="true">▾</span>
        </button>
        <p v-if="error" class="text-sm font-semibold text-danger">{{ error }}</p>
        <p v-else-if="hint" class="text-sm text-muted">{{ hint }}</p>
        <input v-if="required" :value="model" class="sr-only" tabindex="-1" aria-hidden="true" required />

        <Teleport to="body">
            <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
                <div v-if="open" class="fixed inset-0 z-[70]" :class="pos.sheet ? 'flex items-end bg-black/50' : pos.center ? 'bg-black/40' : ''" @mousedown.self="open = false">
                    <div ref="pop" role="dialog" aria-label="Pilih tanggal"
                        class="border border-line bg-surface p-4 shadow-card" :class="pos.sheet ? 'w-full rounded-t-[var(--m-radius)] pb-[max(1rem,env(safe-area-inset-bottom))]' : 'absolute w-80 rounded-2xl'"
                        :style="pos.sheet ? {} : { top: pos.top + 'px', left: pos.left + 'px' }">
                        <div class="mb-3 flex items-center gap-1">
                            <button type="button" class="grid size-9 place-items-center rounded-full hover:bg-surface2" aria-label="Bulan sebelumnya" @click="shiftMonth(-1)">‹</button>
                            <button type="button" class="min-w-0 flex-1 rounded-lg px-2 py-1 font-display text-lg font-bold hover:bg-surface2" @click="mode = mode === 'days' ? 'months' : 'days'">
                                {{ MONTHS[view.m] }} {{ view.y }} <span class="text-xs text-muted">{{ mode === 'days' ? '▾' : '▴' }}</span>
                            </button>
                            <button type="button" class="grid size-9 place-items-center rounded-full hover:bg-surface2" aria-label="Bulan berikutnya" @click="shiftMonth(1)">›</button>
                        </div>

                        <div v-if="mode === 'months'" class="space-y-3">
                            <div class="grid grid-cols-4 gap-1.5">
                                <button v-for="(n, k) in MONTHS" :key="n" type="button" class="rounded-lg py-2 text-sm font-bold transition"
                                    :class="k === view.m ? 'bg-accent text-on-accent' : 'hover:bg-surface2'" @click="view.m = k; mode = 'days'">{{ n.slice(0, 3) }}</button>
                            </div>
                            <div class="grid grid-cols-4 gap-1.5">
                                <button v-for="y in years" :key="y" type="button" class="rounded-lg py-2 text-sm font-bold transition"
                                    :class="y === view.y ? 'bg-accent2 text-bg' : 'hover:bg-surface2'" @click="view.y = y">{{ y }}</button>
                            </div>
                        </div>

                        <template v-else>
                            <div class="mb-1 grid grid-cols-7 text-center text-[11px] font-bold text-muted uppercase">
                                <span v-for="d in DAYS" :key="d" class="py-1">{{ d }}</span>
                            </div>
                            <div class="grid grid-cols-7 gap-y-0.5" role="grid">
                                <button v-for="c in cells" :key="c.v" type="button" :disabled="c.off" :aria-label="c.v" :aria-pressed="c.v === selectedDate"
                                    class="mx-auto grid size-10 place-items-center rounded-full text-sm font-semibold transition sm:size-9"
                                    :class="[
                                        c.v === selectedDate ? 'bg-accent text-on-accent shadow-card' : c.v === todayIso ? 'ring-2 ring-accent3' : 'hover:bg-surface2',
                                        c.other && c.v !== selectedDate ? 'text-muted/50' : '',
                                        c.off ? 'cursor-not-allowed opacity-30 hover:bg-transparent' : '',
                                    ]" @click="pick(c)">{{ c.day }}</button>
                            </div>
                        </template>

                        <div v-if="isDT && mode === 'days'" class="mt-3 border-t border-line pt-3">
                            <div class="flex items-center justify-center gap-2" role="group" aria-label="Jam">
                                <div class="flex items-center gap-1">
                                    <button type="button" class="grid size-9 place-items-center rounded-full bg-surface2 font-bold" aria-label="Kurangi jam" @click="step('h', -1)">−</button>
                                    <span class="w-10 text-center font-mono text-2xl font-bold">{{ p2(time.h) }}</span>
                                    <button type="button" class="grid size-9 place-items-center rounded-full bg-surface2 font-bold" aria-label="Tambah jam" @click="step('h', 1)">+</button>
                                </div>
                                <span class="font-mono text-2xl font-bold">:</span>
                                <div class="flex items-center gap-1">
                                    <button type="button" class="grid size-9 place-items-center rounded-full bg-surface2 font-bold" aria-label="Kurangi menit" @click="step('i', -5)">−</button>
                                    <span class="w-10 text-center font-mono text-2xl font-bold">{{ p2(time.i) }}</span>
                                    <button type="button" class="grid size-9 place-items-center rounded-full bg-surface2 font-bold" aria-label="Tambah menit" @click="step('i', 5)">+</button>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap justify-center gap-1.5">
                                <button v-for="q in quick" :key="q" type="button" class="rounded-full px-3 py-1 text-xs font-bold transition"
                                    :class="`${p2(time.h)}:${p2(time.i)}` === q ? 'bg-accent text-on-accent' : 'bg-surface2 hover:bg-line/60'" @click="setQuick(q)">{{ q.replace(':', '.') }}</button>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between gap-2">
                            <button type="button" class="rounded-lg px-3 py-2 text-sm font-bold text-accent hover:bg-surface2" @click="today">Hari ini</button>
                            <button v-if="!required && model" type="button" class="rounded-lg px-3 py-2 text-sm font-bold text-muted hover:text-danger" @click="clear">Hapus</button>
                            <button v-if="isDT" type="button" class="ml-auto rounded-xl bg-accent px-5 py-2 text-sm font-bold text-on-accent" :disabled="!model" @click="hide">Selesai</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
