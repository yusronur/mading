<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';

/** Jadikan template dan salin ke rombel lain / tahun ajaran baru (Tahap 18). */
const props = defineProps({ mode: String, board: Object, classes: { type: Array, default: () => [] } }); // mode: template | classes | null
const emit = defineEmits(['close']);

const title = ref('');
const shared = ref(false);
const withPosts = ref(true);
const picked = ref([]);
const posts = ref('none');
const busy = ref(false);
const error = ref('');

watch(() => props.mode, (m) => {
    if (!m) return;
    title.value = props.board?.title ?? '';
    shared.value = false;
    withPosts.value = true;
    picked.value = [];
    posts.value = 'none';
    error.value = '';
});
const others = computed(() => props.classes.filter((c) => c.id !== props.board?.class_id));
const toggle = (id) => (picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]);

const done = { preserveScroll: true, onSuccess: () => emit('close'), onError: (e) => (error.value = Object.values(e)[0]), onFinish: () => (busy.value = false) };
const saveTemplate = () => { busy.value = true; router.post(route('boards.save-template', props.board.id), { title: title.value, shared: shared.value, with_posts: withPosts.value }, done); };
const copyClasses = () => { busy.value = true; router.post(route('boards.copy-classes', props.board.id), { class_ids: picked.value, posts: posts.value }, done); };
</script>

<template>
    <Modal :show="mode === 'template'" title="Jadikan template" @close="emit('close')">
        <form class="space-y-4" data-testid="template-form" @submit.prevent="saveTemplate">
            <p class="text-sm text-muted">Template menyimpan kolom, pengaturan, rubrik, dan (bila dipilih) kartu contoh buatan guru. Kartu siswa tidak ikut.</p>
            <Field v-model="title" label="Nama template" maxlength="150" :error="error" />
            <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="withPosts" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Sertakan kartu contoh buatan guru</label>
            <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="shared" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Bagikan ke semua guru di sekolah</label>
            <Btn type="submit" block :loading="busy">⭐ Simpan template</Btn>
        </form>
    </Modal>

    <Modal :show="mode === 'classes'" title="Salin ke rombel lain" @close="emit('close')">
        <form class="space-y-4" data-testid="copy-classes-form" @submit.prevent="copyClasses">
            <p class="text-sm text-muted">Papan baru dibuat untuk tiap rombel yang dipilih (tahun ajaran aktif), lengkap dengan kode baru. Cocok untuk memakai ulang papan di tahun ajaran baru.</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="c in others" :key="c.id" type="button" class="rounded-full px-3 py-1.5 text-sm font-bold" :class="picked.includes(c.id) ? 'bg-accent text-on-accent' : 'bg-surface2'"
                    :aria-pressed="picked.includes(c.id)" @click="toggle(c.id)">{{ picked.includes(c.id) ? '✓ ' : '' }}{{ c.name }}</button>
                <p v-if="!others.length" class="text-sm text-muted">Belum ada rombel di tahun ajaran aktif.</p>
            </div>
            <div class="space-y-1 text-sm font-semibold">
                <label class="flex items-center gap-2"><input v-model="posts" type="radio" value="none" class="size-5 accent-[var(--m-accent)]" /> Hanya struktur & pengaturan</label>
                <label class="flex items-center gap-2"><input v-model="posts" type="radio" value="staff" class="size-5 accent-[var(--m-accent)]" /> Beserta kartu buatan guru (petunjuk, contoh)</label>
            </div>
            <p v-if="error" class="text-sm font-semibold text-danger">{{ error }}</p>
            <Btn type="submit" block :loading="busy" :disabled="!picked.length">📤 Salin ke {{ picked.length || '' }} rombel</Btn>
        </form>
    </Modal>
</template>
