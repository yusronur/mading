<script setup>
import { computed } from 'vue';
import { effects, setEffects, beep } from '@/lib/effects';

// Tombol kecil: efek perayaan (konfeti + suara) hidup/mati (LIV-06).
const on = computed(() => effects.confetti || effects.sound);
const toggle = () => {
    const next = !on.value;
    setEffects({ confetti: next, sound: next });
    if (next) beep('pop');
};
</script>

<template>
    <button type="button" class="rounded-lg bg-surface2 px-3 py-1.5 text-sm font-bold" :aria-pressed="on" :title="on ? 'Konfeti dan suara hidup' : 'Konfeti dan suara mati'" @click="toggle">
        {{ on ? '🔊 Efek' : '🔇 Efek' }}
    </button>
</template>
