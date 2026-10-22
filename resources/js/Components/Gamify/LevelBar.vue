<script setup>
defineProps({ g: Object, big: Boolean });
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <p class="font-display font-bold" :class="big ? 'text-2xl' : 'text-base'">Level {{ g.level }} · {{ g.title }}</p>
            <p class="text-sm font-bold text-muted">{{ g.xp }} XP</p>
        </div>
        <div class="mt-1 h-3 overflow-hidden rounded-full bg-surface2" role="progressbar" :aria-valuenow="Math.round(g.progress * 100)" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-accent transition-all duration-700" :style="{ width: Math.max(3, g.progress * 100) + '%' }"></div>
        </div>
        <p class="mt-1 text-xs font-semibold text-muted">{{ g.span - g.into }} XP lagi ke level {{ g.level + 1 }}</p>
    </div>
</template>
