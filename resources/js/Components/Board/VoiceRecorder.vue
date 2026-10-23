<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { toast } from '@/lib/toast';

/** Rekam suara langsung di kartu (Tahap 17). Maksimal 3 menit. */
const emit = defineEmits(['done', 'cancel']);
const MAX = 180;

const state = ref('idle'); // idle | recording | ready
const seconds = ref(0);
const url = ref(null);
let recorder = null;
let stream = null;
let chunks = [];
let timer = null;
let blob = null;

const supported = typeof window !== 'undefined' && !!window.MediaRecorder && !!navigator.mediaDevices?.getUserMedia;
const pickType = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].find((t) => MediaRecorder.isTypeSupported?.(t)) ?? '';
const extFor = (type) => (type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'weba');
const fmt = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

const stopTracks = () => { stream?.getTracks().forEach((t) => t.stop()); stream = null; };

const start = async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
    } catch (e) {
        toast('Mikrofon tidak bisa dipakai. Izinkan akses mikrofon di peramban.', 'error');
        return;
    }
    const type = pickType();
    recorder = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 48000 } : undefined);
    chunks = [];
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    recorder.onstop = () => {
        stopTracks();
        blob = new Blob(chunks, { type: recorder.mimeType || type || 'audio/webm' });
        if (url.value) URL.revokeObjectURL(url.value);
        url.value = URL.createObjectURL(blob);
        state.value = 'ready';
    };
    recorder.start(1000);
    seconds.value = 0;
    state.value = 'recording';
    timer = setInterval(() => {
        seconds.value++;
        if (seconds.value >= MAX) stop();
    }, 1000);
};

const stop = () => {
    clearInterval(timer);
    if (recorder?.state === 'recording') recorder.stop();
};

const redo = () => { state.value = 'idle'; blob = null; };
const use = () => {
    if (!blob) return;
    const type = blob.type.split(';')[0];
    emit('done', new File([blob], `rekaman-suara-${Date.now()}.${extFor(type)}`, { type }));
};
const cancel = () => { stop(); stopTracks(); emit('cancel'); };

onBeforeUnmount(() => {
    clearInterval(timer);
    if (recorder?.state === 'recording') { recorder.onstop = null; recorder.stop(); }
    stopTracks();
    if (url.value) URL.revokeObjectURL(url.value);
});
</script>

<template>
    <div class="space-y-3 rounded-xl border-2 border-line bg-surface2 p-3" data-testid="voice-recorder">
        <p v-if="!supported" class="text-sm font-semibold text-danger">Peramban ini belum mendukung rekam suara. Coba Chrome atau Safari terbaru.</p>
        <template v-else>
            <div class="flex items-center gap-3">
                <button v-if="state !== 'recording'" type="button" class="grid size-14 place-items-center rounded-full bg-danger text-2xl text-bg shadow-card" :aria-label="state === 'ready' ? 'Rekam ulang' : 'Mulai rekam'" data-testid="rec-start" @click="state === 'ready' ? (redo(), start()) : start()">🎙️</button>
                <button v-else type="button" class="grid size-14 place-items-center rounded-full bg-ink text-xl text-bg shadow-card" aria-label="Berhenti" data-testid="rec-stop" @click="stop">■</button>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-2xl font-bold tabular-nums" :class="state === 'recording' ? 'text-danger' : ''">
                        <span v-if="state === 'recording'" class="mr-1 inline-block size-3 animate-pulse rounded-full bg-danger"></span>{{ fmt(seconds) }} <span class="text-sm text-muted">/ {{ fmt(MAX) }}</span>
                    </p>
                    <p class="text-xs text-muted">{{ state === 'recording' ? 'Sedang merekam… ketuk ■ untuk berhenti.' : state === 'ready' ? 'Dengarkan dulu, lalu pakai atau rekam ulang.' : 'Ketuk 🎙️ untuk mulai merekam.' }}</p>
                </div>
            </div>
            <audio v-if="state === 'ready' && url" :src="url" controls class="w-full"></audio>
        </template>
        <div class="flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl px-3 text-sm font-bold hover:bg-line/60" @click="cancel">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-accent px-4 text-sm font-bold text-on-accent disabled:opacity-40" :disabled="state !== 'ready'" data-testid="rec-done" @click="use">✓ Pakai rekaman</button>
        </div>
    </div>
</template>
