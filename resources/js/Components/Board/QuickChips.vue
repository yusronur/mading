<script setup>
import { ref } from 'vue';
import { putJson } from '@/lib/http';
import { toast } from '@/lib/toast';

/** Komentar cepat guru: ketuk untuk menyisipkan; daftar bisa diatur sendiri (Tahap 18). */
const props = defineProps({ items: { type: Array, default: () => [] } });
const emit = defineEmits(['pick', 'update']);
const editing = ref(false);
const draft = ref('');
const list = ref([...props.items]);

const startEdit = () => { draft.value = list.value.join('\n'); editing.value = true; };
const save = async () => {
    try {
        const res = await putJson(route('quick-comments.save'), { items: draft.value.split('\n').slice(0, 20) });
        list.value = res.items;
        emit('update', res.items);
        editing.value = false;
        toast('Komentar cepat disimpan.');
    } catch (e) { toast(e.message, 'error'); }
};
</script>

<template>
    <div class="space-y-1.5" data-testid="quick-chips">
        <div v-if="!editing" class="flex flex-wrap gap-1.5">
            <button v-for="t in list" :key="t" type="button" class="rounded-full bg-surface2 px-2.5 py-1 text-xs font-bold hover:bg-line/60" @click="emit('pick', t)">{{ t }}</button>
            <button type="button" class="rounded-full px-2 py-1 text-xs font-bold text-muted hover:text-ink" title="Atur komentar cepat" @click="startEdit">✎ Atur</button>
        </div>
        <div v-else class="space-y-2">
            <textarea v-model="draft" rows="5" class="w-full rounded-xl border-2 border-line bg-surface px-3 py-2 text-sm text-ink" placeholder="Satu komentar per baris (maks. 20)"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" class="h-9 rounded-xl px-3 text-sm font-bold hover:bg-surface2" @click="editing = false">Batal</button>
                <button type="button" class="h-9 rounded-xl bg-accent px-3 text-sm font-bold text-on-accent" @click="save">Simpan</button>
            </div>
        </div>
    </div>
</template>
