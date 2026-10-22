<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatBytes } from '@/lib/http';

// Versi cetak papan (SHR-04): simpan sebagai PDF lewat dialog cetak peramban.
const props = defineProps({ board: Object, sections: Array, posts: Array, printedAt: String });
const groups = computed(() => {
    if (props.board.format !== 'shelf' || !props.sections.length) return [{ id: null, title: null, posts: props.posts }];
    return props.sections.map((s, i) => ({ ...s, posts: props.posts.filter((p) => p.section_id === s.id || (!p.section_id && i === 0)) }));
});
const print = () => window.print();
</script>

<template>
    <Head :title="'Cetak: ' + board.title" />
    <div class="min-h-dvh bg-white px-4 py-6 text-slate-900 print:p-0">
        <div class="mx-auto mb-6 flex max-w-4xl flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-100 px-4 py-3 print:hidden">
            <p class="text-sm text-slate-600">Pilih <b>Simpan sebagai PDF</b> di dialog cetak untuk mendapatkan berkas PDF.</p>
            <button class="h-11 rounded-xl bg-slate-900 px-6 font-bold text-white" @click="print">🖨️ Cetak / PDF</button>
        </div>
        <article class="mx-auto max-w-4xl">
            <header class="mb-6 border-b-2 border-slate-900 pb-3">
                <h1 class="text-3xl font-bold"><span v-if="board.icon">{{ board.icon }} </span>{{ board.title }}</h1>
                <p v-if="board.description" class="mt-1 whitespace-pre-line text-slate-700">{{ board.description }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ [board.class_name, board.owner, `${posts.length} kartu`, `dicetak ${printedAt}`].filter(Boolean).join(' · ') }}</p>
            </header>
            <section v-for="g in groups" :key="g.id ?? 'all'" class="mb-6">
                <h2 v-if="g.title" class="mb-3 border-b border-slate-300 pb-1 text-xl font-bold">{{ g.title }}</h2>
                <div class="columns-1 gap-4 sm:columns-2 print:columns-2">
                    <div v-for="p in g.posts" :key="p.id" class="mb-4 break-inside-avoid rounded-lg border border-slate-300 p-3">
                        <p class="text-[11px] font-bold text-slate-500">{{ p.author }}{{ p.is_teacher ? ' · guru' : '' }} · {{ new Date(p.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) }}</p>
                        <h3 v-if="p.title" class="mt-1 font-bold">{{ p.title }}</h3>
                        <div v-if="p.body" class="rich mt-1 text-sm" v-html="p.body"></div>
                        <div v-if="p.attachments?.some((a) => a.kind === 'image')" class="mt-2 grid grid-cols-2 gap-1">
                            <img v-for="a in p.attachments.filter((a) => a.kind === 'image').slice(0, 4)" :key="a.id" :src="a.thumb_url || a.url" :alt="a.name" class="w-full rounded object-cover" />
                        </div>
                        <p v-if="p.link" class="mt-2 text-xs break-all text-slate-600">🔗 {{ p.link.title }} — {{ p.link.url }}</p>
                        <p v-for="a in (p.attachments ?? []).filter((a) => a.kind !== 'image')" :key="a.id" class="text-xs text-slate-600">📎 {{ a.name }} ({{ formatBytes(a.size) }})</p>
                        <p v-if="p.comment_count || p.reaction?.count" class="mt-1 text-[11px] text-slate-500">
                            <span v-if="p.reaction?.count">Reaksi {{ p.reaction.count }} </span><span v-if="p.comment_count">· {{ p.comment_count }} komentar</span>
                        </p>
                    </div>
                </div>
            </section>
        </article>
    </div>
</template>
