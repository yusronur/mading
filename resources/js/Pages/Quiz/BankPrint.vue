<script setup>
import { computed, reactive } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import MathHtml from '@/Components/Quiz/MathHtml.vue';

// Cetak bank soal: tampilan ringkas, dengan atau tanpa kunci (pengerjaan luring).
const props = defineProps({ bank: Object, questions: Array, printedAt: String });
const school = computed(() => usePage().props.school);

const opt = reactive({ key: false, explanation: false, points: false, twoCols: true, size: 'm', identity: true, sheet: true });
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const sizes = { s: 'text-[11px] leading-snug', m: 'text-[13px] leading-snug', l: 'text-[15px] leading-normal' };

// Pilihan pendek tampil sebaris agar hemat kertas
const inline = (q) => q.options.length <= 4 && q.options.every((o) => o.text.replace(/<[^>]+>/g, '').length <= 26);
const keyText = (q) => {
    if (q.type === 'mc' || q.type === 'mcm') return q.options.map((o, i) => (o.correct ? LETTERS[i] : null)).filter(Boolean).join(', ');
    if (q.type === 'tf') return q.options.find((o) => o.correct)?.text ?? '';
    if (q.type === 'match') return q.match_key.map((l, i) => `${i + 1}-${l}`).join('  ');
    return (q.answers || []).join(' / ');
};
const total = computed(() => props.questions.reduce((s, q) => s + q.points, 0));
const print = () => window.print();
</script>

<template>
    <Head :title="`Cetak ${bank.name}`" />
    <div class="min-h-dvh bg-white p-4 text-black print:p-0">
        <!-- Bilah pilihan: tidak ikut tercetak -->
        <div class="mx-auto mb-5 max-w-[210mm] rounded-xl bg-slate-100 px-4 py-3 print:hidden">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-bold">{{ questions.length }} soal · {{ total }} poin</p>
                <button class="h-11 rounded-xl bg-slate-900 px-6 font-bold text-white" @click="print">🖨️ Cetak / PDF</button>
            </div>
            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold">
                <label class="inline-flex items-center gap-2"><input v-model="opt.key" type="checkbox" class="size-5" /> Tampilkan kunci jawaban</label>
                <label class="inline-flex items-center gap-2" :class="opt.key ? '' : 'opacity-40'"><input v-model="opt.explanation" type="checkbox" class="size-5" :disabled="!opt.key" /> Pembahasan</label>
                <label class="inline-flex items-center gap-2"><input v-model="opt.points" type="checkbox" class="size-5" /> Bobot poin</label>
                <label class="inline-flex items-center gap-2"><input v-model="opt.identity" type="checkbox" class="size-5" /> Kolom nama &amp; nilai</label>
                <label class="inline-flex items-center gap-2"><input v-model="opt.twoCols" type="checkbox" class="size-5" /> Dua kolom</label>
                <label class="inline-flex items-center gap-2">Huruf
                    <select v-model="opt.size" class="h-9 rounded-lg border border-slate-300 bg-white px-2"><option value="s">Kecil</option><option value="m">Sedang</option><option value="l">Besar</option></select>
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-600">Pilih <b>Simpan sebagai PDF</b> di dialog cetak untuk berkas PDF. Tanpa kunci = lembar siswa; dengan kunci = lembar guru.</p>
        </div>

        <article class="mx-auto max-w-[210mm]" :class="sizes[opt.size]">
            <header class="mb-4 border-b-2 border-black pb-2">
                <div class="flex items-end justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[0.85em] font-bold tracking-wide uppercase">{{ school?.name }}</p>
                        <h1 class="text-[1.5em] leading-tight font-bold">{{ bank.name }}</h1>
                        <p class="text-[0.85em] text-slate-700">{{ [bank.subject, bank.grade ? 'Kelas ' + bank.grade : null, bank.owner, printedAt].filter(Boolean).join(' · ') }}</p>
                    </div>
                    <p v-if="opt.key" class="shrink-0 rounded border-2 border-black px-2 py-0.5 text-[0.85em] font-bold uppercase">Kunci jawaban</p>
                </div>
                <div v-if="opt.identity && !opt.key" class="mt-3 grid grid-cols-[1fr_auto_auto] gap-x-6 text-[0.9em]">
                    <p>Nama : <span class="inline-block w-[75%] border-b border-dotted border-black align-bottom">&nbsp;</span></p>
                    <p>Kelas : <span class="inline-block w-16 border-b border-dotted border-black align-bottom">&nbsp;</span></p>
                    <p>Nilai : <span class="inline-block w-16 border-b border-dotted border-black align-bottom">&nbsp;</span></p>
                </div>
            </header>

            <p v-if="!questions.length" class="py-10 text-center text-slate-600">Bank ini belum berisi soal.</p>

            <ol :class="opt.twoCols ? 'columns-2 gap-8 print:gap-6' : ''">
                <li v-for="(q, i) in questions" :key="q.id" class="mb-3 flex break-inside-avoid gap-2">
                    <span class="w-6 shrink-0 text-right font-bold">{{ i + 1 }}.</span>
                    <div class="min-w-0 flex-1">
                        <MathHtml :html="q.prompt" class="rich [&_p]:my-0" />
                        <p v-if="opt.points" class="text-[0.8em] text-slate-500">({{ q.points }} poin)</p>

                        <div v-if="q.media.some((m) => m.kind === 'image')" class="mt-1 flex flex-wrap gap-1.5">
                            <img v-for="m in q.media.filter((x) => x.kind === 'image')" :key="m.id" :src="m.thumb_url || m.url" :alt="m.name" class="max-h-32 max-w-full object-contain" />
                        </div>
                        <p v-if="q.media.some((m) => m.kind !== 'image')" class="text-[0.8em] text-slate-500 italic">[Soal ini memakai audio/video, tidak ikut tercetak]</p>

                        <!-- Pilihan ganda & benar/salah -->
                        <ul v-if="q.type === 'mc' || q.type === 'mcm' || q.type === 'tf'" class="mt-1" :class="inline(q) || q.type === 'tf' ? 'flex flex-wrap gap-x-5 gap-y-0.5' : 'space-y-0.5'">
                            <li v-for="(o, j) in q.options" :key="o.id" class="flex gap-1.5" :class="opt.key && o.correct ? 'font-bold underline decoration-2' : ''">
                                <span class="shrink-0">{{ q.type === 'tf' ? (q.type === 'tf' && opt.key && o.correct ? '●' : '○') : LETTERS[j] + '.' }}</span>
                                <MathHtml :html="o.text" plain tag="span" />
                            </li>
                        </ul>
                        <p v-if="q.type === 'mcm'" class="text-[0.8em] text-slate-500 italic">(jawaban boleh lebih dari satu)</p>

                        <!-- Isian singkat -->
                        <p v-else-if="q.type === 'short'" class="mt-1">
                            Jawab : <span v-if="!opt.key" class="inline-block w-40 border-b border-dotted border-black align-bottom">&nbsp;</span>
                            <b v-else class="underline decoration-2">{{ keyText(q) }}</b>
                        </p>

                        <!-- Menjodohkan -->
                        <div v-else-if="q.type === 'match'" class="mt-1 grid grid-cols-2 gap-x-4">
                            <ul class="space-y-0.5">
                                <li v-for="(l, j) in q.lefts" :key="l.id" class="flex gap-1.5">
                                    <span class="shrink-0">{{ j + 1 }}.</span><span class="flex-1">{{ l.text }}</span>
                                    <b v-if="opt.key" class="shrink-0 underline decoration-2">{{ q.match_key[j] }}</b>
                                    <span v-else class="w-8 shrink-0 border-b border-dotted border-black">&nbsp;</span>
                                </li>
                            </ul>
                            <ul class="space-y-0.5">
                                <li v-for="(r, j) in q.rights" :key="r.id" class="flex gap-1.5"><span class="shrink-0">{{ LETTERS[j] }}.</span><span>{{ r.text }}</span></li>
                            </ul>
                        </div>

                        <div v-if="opt.key && opt.explanation && q.explanation" class="mt-1 border-l-2 border-slate-400 pl-2 text-[0.9em] text-slate-700">
                            <MathHtml :html="q.explanation" class="rich [&_p]:my-0" />
                        </div>
                    </div>
                </li>
            </ol>

            <!-- Ringkasan kunci -->
            <section v-if="opt.key && questions.length" class="mt-4 break-inside-avoid border-t-2 border-black pt-2">
                <h2 class="mb-1 text-[1em] font-bold">Ringkasan kunci</h2>
                <ul class="grid grid-cols-3 gap-x-4 gap-y-0.5 text-[0.9em]">
                    <li v-for="(q, i) in questions" :key="q.id"><b>{{ i + 1 }}.</b> {{ keyText(q) }}</li>
                </ul>
            </section>
        </article>
    </div>
</template>
