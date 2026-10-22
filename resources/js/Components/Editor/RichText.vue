<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';

// Editor teks berformat sederhana (PST-01). HTML tetap dibersihkan server.
const props = defineProps({ modelValue: String, placeholder: String, label: String, error: String });
const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            heading: false, codeBlock: false, horizontalRule: false,
            link: { openOnClick: false, autolink: true, defaultProtocol: 'https', protocols: ['http', 'https', 'mailto'] },
        }),
        Placeholder.configure({ placeholder: props.placeholder || 'Tulis di sini…' }),
    ],
    editorProps: { attributes: { class: 'rich min-h-28 px-4 py-3 focus:outline-none' } },
    onUpdate: ({ editor }) => emit('update:modelValue', editor.isEmpty ? '' : editor.getHTML()),
});

watch(() => props.modelValue, (v) => {
    if (editor.value && v !== editor.value.getHTML() && !(v === '' && editor.value.isEmpty)) {
        editor.value.commands.setContent(v || '', { emitUpdate: false });
    }
});
onBeforeUnmount(() => editor.value?.destroy());

const linkOpen = defineModel('linkOpen', { default: false });
let linkUrl = '';
const toggleLink = () => {
    const e = editor.value;
    if (e.isActive('link')) return e.chain().focus().unsetLink().run();
    linkOpen.value = true;
    linkUrl = '';
};
const applyLink = (url) => {
    linkOpen.value = false;
    url = url.trim();
    if (!url) return;
    if (!/^(https?:|mailto:)/i.test(url)) url = 'https://' + url;
    const e = editor.value;
    if (e.state.selection.empty) e.chain().focus().insertContent(`<a href="${url.replace(/"/g, '&quot;')}">${url.replace(/</g, '&lt;')}</a> `).run();
    else e.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};

const tools = [
    { key: 'bold', label: 'B', title: 'Tebal', cls: 'font-black', run: (e) => e.chain().focus().toggleBold().run() },
    { key: 'italic', label: 'I', title: 'Miring', cls: 'italic font-serif', run: (e) => e.chain().focus().toggleItalic().run() },
    { key: 'underline', label: 'U', title: 'Garis bawah', cls: 'underline', run: (e) => e.chain().focus().toggleUnderline().run() },
    { key: 'strike', label: 'S', title: 'Coret', cls: 'line-through', run: (e) => e.chain().focus().toggleStrike().run() },
    { key: 'bulletList', label: '•≡', title: 'Daftar berbutir', run: (e) => e.chain().focus().toggleBulletList().run() },
    { key: 'orderedList', label: '1.', title: 'Daftar bernomor', run: (e) => e.chain().focus().toggleOrderedList().run() },
    { key: 'blockquote', label: '❝', title: 'Kutipan', run: (e) => e.chain().focus().toggleBlockquote().run() },
];
</script>

<template>
    <div class="space-y-1.5">
        <p v-if="label" class="block text-sm font-bold text-ink">{{ label }}</p>
        <div class="overflow-hidden rounded-xl border-2 bg-surface focus-within:border-accent" :class="error ? 'border-danger' : 'border-line'">
            <div v-if="editor" class="flex flex-wrap gap-0.5 border-b border-line bg-surface2/60 p-1">
                <button v-for="t in tools" :key="t.key" type="button" :title="t.title" :aria-label="t.title" :aria-pressed="editor.isActive(t.key)"
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm transition" :class="[t.cls, editor.isActive(t.key) ? 'bg-accent text-on-accent' : 'hover:bg-surface']"
                    @mousedown.prevent @click="t.run(editor)">{{ t.label }}</button>
                <button type="button" title="Tautan" aria-label="Tautan" class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm"
                    :class="editor.isActive('link') ? 'bg-accent text-on-accent' : 'hover:bg-surface'" @mousedown.prevent @click="toggleLink">🔗</button>
            </div>
            <form v-if="linkOpen" class="flex gap-2 border-b border-line p-2" @submit.prevent="applyLink($refs.url.value)">
                <input ref="url" type="url" inputmode="url" placeholder="https://…" autofocus class="h-9 min-w-0 flex-1 rounded-lg border-2 border-line bg-surface px-3 text-sm text-ink focus:border-accent focus:outline-none" />
                <button type="submit" class="rounded-lg bg-accent px-3 text-sm font-bold text-on-accent">Pasang</button>
                <button type="button" class="rounded-lg px-2 text-sm text-muted" @click="linkOpen = false">Batal</button>
            </form>
            <EditorContent :editor="editor" />
        </div>
        <p v-if="error" class="text-sm font-semibold text-danger">{{ error }}</p>
    </div>
</template>
