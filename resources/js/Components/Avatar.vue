<script setup>
// Avatar siswa: emoji pilihan (atau inisial) dengan bingkai berupa cincin warna (GAM-05).
defineProps({ avatar: String, frame: String, initials: String, size: { type: Number, default: 32 } });
</script>

<template>
    <span class="inline-grid shrink-0 place-items-center rounded-full" :style="{ width: size + 'px', height: size + 'px', background: frame || 'transparent', padding: frame ? Math.max(2, Math.round(size / 14)) + 'px' : '0' }">
        <span class="grid size-full place-items-center rounded-full bg-accent2 font-bold text-bg" :style="{ fontSize: size * (avatar ? 0.55 : 0.4) + 'px', lineHeight: 1 }" :data-avatar="avatar ? 'emoji' : 'initials'">
            {{ avatar || initials }}
        </span>
    </span>
</template>
