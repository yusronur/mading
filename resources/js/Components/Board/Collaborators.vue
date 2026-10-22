<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import { getJson, postJson, deleteJson } from '@/lib/http';
import { toast } from '@/lib/toast';

// Kolaborator guru (co-admin papan, BRD-19).
const props = defineProps({ show: Boolean, boardId: String });
const emit = defineEmits(['close']);
const list = ref([]);
const candidates = ref([]);
const q = ref('');
let timer;

const load = async () => {
    try {
        const res = await getJson(route('boards.collaborators', props.boardId) + (q.value ? `?q=${encodeURIComponent(q.value)}` : ''));
        list.value = res.collaborators;
        candidates.value = res.candidates;
    } catch (e) { toast(e.message, 'error'); }
};
watch(() => props.show, (v) => v && load());
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 250); });

const add = async (u) => {
    try {
        const res = await postJson(route('boards.collaborators.add', props.boardId) + (q.value ? `?q=${encodeURIComponent(q.value)}` : ''), { user_id: u.id });
        list.value = res.collaborators; candidates.value = res.candidates;
        toast(`${u.name} ditambahkan sebagai kolaborator`);
    } catch (e) { toast(e.message, 'error'); }
};
const remove = async (u) => {
    try {
        const res = await deleteJson(route('boards.collaborators.remove', [props.boardId, u.id]));
        list.value = res.collaborators;
    } catch (e) { toast(e.message, 'error'); }
};
</script>

<template>
    <Modal :show="show" title="Kolaborator guru" @close="emit('close')">
        <p class="mb-4 text-sm text-muted">Kolaborator bisa mengelola isi dan pengaturan papan (moderasi, kolom, format), tetapi tidak bisa menghapus papan atau mengubah daftar kolaborator.</p>
        <ul class="mb-4 space-y-2">
            <li v-for="u in list" :key="u.id" class="flex items-center justify-between rounded-xl bg-surface2/70 px-3 py-2">
                <span><span class="font-bold">{{ u.name }}</span> <span class="text-xs text-muted">{{ u.username }}</span></span>
                <Btn size="sm" variant="danger" @click="remove(u)">Lepas</Btn>
            </li>
            <li v-if="!list.length" class="text-sm text-muted">Belum ada kolaborator.</li>
        </ul>
        <input v-model="q" type="search" placeholder="Cari nama atau NIP guru…" class="h-11 w-full rounded-xl border-2 border-line bg-surface px-3 text-ink focus:border-accent focus:outline-none" />
        <ul v-if="candidates.length" class="mt-2 space-y-1">
            <li v-for="u in candidates" :key="u.id" class="flex items-center justify-between rounded-xl px-3 py-2 hover:bg-surface2">
                <span><span class="font-bold">{{ u.name }}</span> <span class="text-xs text-muted">{{ u.username }}</span></span>
                <Btn size="sm" @click="add(u)">+ Tambah</Btn>
            </li>
        </ul>
    </Modal>
</template>
