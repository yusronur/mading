<script setup>
import { computed, ref, watch } from 'vue';
import { postJson } from '@/lib/http';
import { toast } from '@/lib/toast';

/** Daftar centang di kartu. Centang langsung tersimpan (tanpa membuka form ubah). */
const props = defineProps({ post: Object, boardId: String });
const items = ref([...(props.post.checklist ?? [])]);
watch(() => props.post.checklist, (v) => (items.value = [...(v ?? [])]));
const doneCount = computed(() => items.value.filter((i) => i.done).length);
const busy = ref(-1);

const toggle = async (i) => {
    if (!props.post.can_check || busy.value !== -1) return;
    const before = items.value[i].done;
    items.value[i] = { ...items.value[i], done: !before };
    busy.value = i;
    try {
        const res = await postJson(route('posts.check', [props.boardId, props.post.id, i]), { done: !before });
        items.value = res.checklist;
    } catch (e) {
        items.value[i] = { ...items.value[i], done: before };
        toast(e.message || 'Gagal menyimpan centang.', 'error');
    } finally {
        busy.value = -1;
    }
};
</script>

<template>
    <div v-if="items.length" class="mt-2" data-testid="checklist" @click.stop>
        <div class="mb-1.5 flex items-center gap-2 text-xs font-bold text-muted">
            <span>☑️ {{ doneCount }}/{{ items.length }}</span>
            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface2"><span class="block h-full bg-success transition-all" :style="{ width: (doneCount / items.length) * 100 + '%' }"></span></span>
        </div>
        <ul class="space-y-1">
            <li v-for="(it, i) in items" :key="i">
                <label class="flex items-start gap-2 rounded-lg px-1 py-0.5 text-sm" :class="post.can_check ? 'cursor-pointer hover:bg-surface2/70' : ''">
                    <input type="checkbox" :checked="it.done" :disabled="!post.can_check" class="mt-0.5 size-5 shrink-0 accent-[var(--m-accent)]" :data-testid="`check-${i}`" @change="toggle(i)" />
                    <span class="break-words" :class="it.done ? 'text-muted line-through' : ''">{{ it.text }}</span>
                </label>
            </li>
        </ul>
    </div>
</template>
