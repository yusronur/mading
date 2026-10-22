// Efek perayaan (konfeti dan suara) yang bisa dimatikan pengguna (LIV-06).
// Pilihan disimpan di peramban masing-masing; bawaannya hidup.
import { reactive } from 'vue';

const KEY = 'madingo:effects';
const read = () => {
    try { return { confetti: true, sound: true, ...JSON.parse(localStorage.getItem(KEY) || '{}') }; } catch (e) { return { confetti: true, sound: true }; }
};

export const effects = reactive(read());

export function setEffects(patch) {
    Object.assign(effects, patch);
    try { localStorage.setItem(KEY, JSON.stringify({ confetti: effects.confetti, sound: effects.sound })); } catch (e) { /* abaikan */ }
}

let ctx = null;
const audio = () => {
    try {
        ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        return ctx;
    } catch (e) { return null; }
};

const NOTES = {
    tick: [[880, 0.06]],
    end: [[660, 0.18], [660, 0.18], [880, 0.45]],
    win: [[523, 0.12], [659, 0.12], [784, 0.12], [1047, 0.4]],
    pop: [[740, 0.08], [988, 0.1]],
};

/** Bunyi pendek buatan sendiri (tanpa berkas audio). Dilewati bila suara dimatikan. */
export function beep(kind = 'pop') {
    if (!effects.sound) return;
    const a = audio();
    if (!a) return;
    let t = a.currentTime;
    for (const [freq, len] of NOTES[kind] ?? NOTES.pop) {
        const o = a.createOscillator();
        const g = a.createGain();
        o.type = 'sine';
        o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + len);
        o.connect(g).connect(a.destination);
        o.start(t);
        o.stop(t + len + 0.02);
        t += len * 0.9;
    }
}
