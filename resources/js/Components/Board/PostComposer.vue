<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Btn from '@/Components/Btn.vue';
import Field from '@/Components/Field.vue';
import Modal from '@/Components/Modal.vue';
import RichText from '@/Components/Editor/RichText.vue';
import LinkPreview from '@/Components/Board/LinkPreview.vue';
import { CARD_COLORS } from '@/lib/colors';
import { upload, postJson, deleteJson, formatBytes } from '@/lib/http';
import { toast } from '@/lib/toast';

/** Form tempel/ubah kartu: teks berformat, warna, lampiran, tautan. */
const props = defineProps({
    show: Boolean,
    board: Object,
    sections: { type: Array, default: () => [] },
    post: { type: Object, default: null }, // null = kartu baru
    sectionId: { type: String, default: null },
});
const emit = defineEmits(['close']);

const form = useForm({ title: '', body: '', color: null, link: '', is_anonymous: false, section_id: null, attachment_ids: [] });
const files = ref([]); // { key, id?, name, size, kind, thumb_url, progress, error }
const preview = ref(null);
const previewLoading = ref(false);
const showLink = ref(false);
const isEdit = computed(() => !!props.post);

watch(() => props.show, (open) => {
    if (!open) return;
    const p = props.post;
    form.defaults({
        title: p?.title ?? '',
        body: p?.body ?? '',
        color: p?.color ?? null,
        link: p?.link?.url ?? '',
        is_anonymous: p?.is_anonymous ?? false,
        section_id: p?.section_id ?? props.sectionId ?? props.sections[0]?.id ?? null,
        attachment_ids: [],
    }).reset();
    form.clearErrors();
    files.value = (p?.attachments ?? []).map((a) => ({ key: a.id, ...a, progress: 100 }));
    preview.value = p?.link ? { ...p.link, image: p.link.image_url } : null;
    showLink.value = !!p?.link;
});

const uploading = computed(() => files.value.some((f) => f.progress < 100 && !f.error));

const pick = async (event) => {
    const list = [...event.target.files];
    event.target.value = '';
    for (const file of list) {
        if (files.value.length >= 10) { toast('Maksimal 10 lampiran per kartu.', 'error'); break; }
        const item = { key: `${Date.now()}-${Math.random()}`, name: file.name, size: file.size, kind: file.type.split('/')[0], progress: 0, local: file.type.startsWith('image/') ? URL.createObjectURL(file) : null };
        files.value.push(item);
        const ref_ = files.value[files.value.length - 1];
        try {
            const res = await upload(route('attachments.store', props.board.id), file, (p) => (ref_.progress = Math.min(p, 99)));
            Object.assign(ref_, res, { progress: 100 });
        } catch (e) {
            ref_.error = e.message;
            toast(`${file.name}: ${e.message}`, 'error');
        }
    }
};

const removeFile = async (f) => {
    files.value = files.value.filter((x) => x.key !== f.key);
    // Lampiran baru (belum menempel di kartu) langsung dihapus dari server
    if (f.id && !(props.post?.attachments ?? []).some((a) => a.id === f.id)) {
        deleteJson(route('attachments.destroy', f.id)).catch(() => {});
    }
};

let lastUrl = '';
const fetchPreview = async () => {
    const url = form.link.trim();
    if (!url || url === lastUrl) return;
    lastUrl = url;
    previewLoading.value = true;
    try {
        preview.value = await postJson(route('posts.preview-link', props.board.id), { url: /^https?:\/\//i.test(url) ? url : `https://${url}` });
        if (!/^https?:\/\//i.test(url)) form.link = preview.value.url;
    } catch (e) {
        preview.value = null;
        form.setError('link', e.message);
    } finally {
        previewLoading.value = false;
    }
};
const onPaste = () => setTimeout(fetchPreview, 50);
const clearLink = () => { form.link = ''; preview.value = null; lastUrl = ''; showLink.value = false; };

const submit = () => {
    if (uploading.value) return toast('Tunggu unggahan selesai.', 'error');
    form.attachment_ids = files.value.filter((f) => f.id && !f.error).map((f) => f.id);
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (isEdit.value) form.put(route('posts.update', [props.board.id, props.post.id]), opts);
    else form.post(route('posts.store', props.board.id), opts);
};

const icon = (f) => ({ audio: '🎵', video: '🎬' }[f.kind] || (f.name?.toLowerCase().endsWith('.pdf') ? '📄' : '📎'));
</script>

<template>
    <Modal :show="show" :title="isEdit ? 'Ubah kartu' : 'Tempel kartu'" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <Field v-if="!isEdit && board.format === 'shelf' && sections.length > 1" v-model="form.section_id" as="select" label="Kolom">
                <option v-for="c in sections" :key="c.id" :value="c.id">{{ c.title }}</option>
            </Field>
            <Field v-model="form.title" label="Judul" maxlength="200" :error="form.errors.title" placeholder="Judul singkat" />
            <RichText v-model="form.body" label="Isi" placeholder="Tulis idemu di sini…" :error="form.errors.body" />

            <!-- Lampiran -->
            <div v-if="files.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                <div v-for="f in files" :key="f.key" class="relative overflow-hidden rounded-xl border border-line bg-surface2" :class="f.error ? 'border-danger' : ''">
                    <img v-if="f.thumb_url || f.local" :src="f.thumb_url || f.local" :alt="f.name" class="aspect-square w-full object-cover" />
                    <div v-else class="grid aspect-square place-items-center p-2 text-center">
                        <span class="text-3xl">{{ icon(f) }}</span>
                        <span class="line-clamp-2 text-[11px] font-semibold break-all">{{ f.name }}</span>
                    </div>
                    <div v-if="f.progress < 100 && !f.error" class="absolute inset-x-0 bottom-0 h-1.5 bg-black/30"><div class="h-full bg-accent transition-all" :style="{ width: f.progress + '%' }"></div></div>
                    <p v-if="f.error" class="absolute inset-x-0 bottom-0 bg-danger px-1 text-[10px] font-bold text-bg">gagal</p>
                    <span v-else-if="f.size" class="absolute bottom-1 left-1 rounded bg-black/60 px-1 text-[10px] text-white">{{ formatBytes(f.size) }}</span>
                    <button type="button" class="absolute top-1 right-1 grid size-7 place-items-center rounded-full bg-black/60 text-sm text-white" :aria-label="`Hapus ${f.name}`" @click="removeFile(f)">✕</button>
                </div>
            </div>

            <!-- Tautan -->
            <div v-if="showLink" class="space-y-2">
                <Field v-model="form.link" type="url" inputmode="url" label="Tautan" placeholder="https://… (YouTube juga bisa)" :error="form.errors.link"
                    @blur="fetchPreview" @keydown.enter.prevent="fetchPreview" @paste="onPaste" />
                <p v-if="previewLoading" class="text-sm text-muted">Memuat pratinjau…</p>
                <div v-else-if="preview" class="relative">
                    <LinkPreview :link="preview" compact />
                    <button type="button" class="absolute top-2 right-2 grid size-7 place-items-center rounded-full bg-black/60 text-sm text-white" aria-label="Hapus tautan" @click="clearLink">✕</button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex h-10 cursor-pointer items-center gap-1.5 rounded-xl bg-surface2 px-3 text-sm font-bold hover:bg-line/60">
                    🖼️ Foto/berkas
                    <input type="file" multiple class="sr-only" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,audio/*,video/*" @change="pick" />
                </label>
                <button v-if="!showLink" type="button" class="h-10 rounded-xl bg-surface2 px-3 text-sm font-bold hover:bg-line/60" @click="showLink = true">🔗 Tautan</button>
                <div class="ml-auto flex items-center gap-1" role="radiogroup" aria-label="Warna kartu">
                    <button type="button" role="radio" :aria-checked="!form.color" title="Tanpa warna" class="size-7 rounded-full border-2 bg-surface" :class="!form.color ? 'border-ink' : 'border-line'" @click="form.color = null"></button>
                    <button v-for="(hex, key) in CARD_COLORS" :key="key" type="button" role="radio" :aria-checked="form.color === key" :title="key"
                        class="size-7 rounded-full border-2" :class="form.color === key ? 'border-ink scale-110' : 'border-transparent'" :style="{ background: hex }" @click="form.color = key"></button>
                </div>
            </div>

            <label v-if="board.allow_anonymous && (!isEdit || post?.is_mine)" class="flex items-center gap-2 text-sm font-semibold">
                <input v-model="form.is_anonymous" type="checkbox" class="size-5 accent-[var(--m-accent)]" /> Kirim sebagai anonim (guru tetap bisa melihat penulisnya)
            </label>

            <p v-if="form.errors.attachment_ids" class="text-sm font-semibold text-danger">{{ form.errors.attachment_ids }}</p>
            <Btn type="submit" block size="lg" :loading="form.processing" :disabled="uploading">{{ isEdit ? 'Simpan perubahan' : 'Tempel! 📌' }}</Btn>
        </form>
    </Modal>
</template>
