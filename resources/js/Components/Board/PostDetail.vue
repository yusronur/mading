<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import PostCard from '@/Components/Board/PostCard.vue';
import Reactions from '@/Components/Board/Reactions.vue';
import GradePanel from '@/Components/Board/GradePanel.vue';
import QuickChips from '@/Components/Board/QuickChips.vue';
import { getJson, postJson, deleteJson } from '@/lib/http';
import { askConfirm } from '@/lib/confirm';
import { toast } from '@/lib/toast';
import { refreshGamify } from '@/lib/gamify';
import { timeAgo } from '@/lib/theme';

// Kartu lengkap + utas komentar (INT-01).
const props = defineProps({ post: Object, board: Object, can: Object, quick: { type: Array, default: null } });
const quickList = ref(props.quick ?? []);
const addQuick = (t) => (body.value = body.value ? `${body.value.trim()} ${t}` : t);
const emit = defineEmits(['close', 'edit', 'pin', 'remove', 'open-media', 'moderate', 'report']);

const comments = ref([]);
const loading = ref(false);
const body = ref('');
const anonymous = ref(false);
const replyTo = ref(null);
const sending = ref(false);

const load = async () => {
    if (!props.post) return;
    loading.value = !comments.value.length;
    try {
        comments.value = (await getJson(route('comments.index', [props.board.id, props.post.id]))).comments;
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        loading.value = false;
    }
};
watch(() => props.post?.id, () => { comments.value = []; replyTo.value = null; body.value = ''; load(); }, { immediate: true });
// Komentar baru dari orang lain: jumlahnya berubah lewat sinkron papan
watch(() => props.post?.comment_count, (n, old) => old !== undefined && n !== old && load());

const canComment = computed(() => props.can.comment && (props.board.allow_comments || props.can.manage));
const total = computed(() => comments.value.reduce((n, c) => n + 1 + c.replies.length, 0));

const send = async () => {
    if (!body.value.trim() || sending.value) return;
    sending.value = true;
    try {
        const res = await postJson(route('comments.store', [props.board.id, props.post.id]), {
            body: body.value, parent_id: replyTo.value?.id ?? null, is_anonymous: anonymous.value,
        });
        comments.value = res.comments;
        if (res.held) toast('Komentar ditahan dan menunggu persetujuan guru.');
        body.value = '';
        replyTo.value = null;
        if (!res.held) refreshGamify();
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        sending.value = false;
    }
};

const removeComment = async (c) => {
    const ok = await askConfirm({ title: 'Hapus komentar?', message: c.parent_id ? '' : 'Balasan di bawahnya ikut terhapus.', confirmText: 'Hapus', tone: 'danger' });
    if (!ok) return;
    try {
        comments.value = (await deleteJson(route('comments.destroy', [props.board.id, c.id]))).comments;
    } catch (e) {
        toast(e.message, 'error');
    }
};
</script>

<template>
    <Modal :show="!!post" :title="post?.title || 'Kartu'" @close="emit('close')">
        <div v-if="post" class="space-y-5">
            <PostCard :post="post" :manage="can.manage" :moderate="can.moderate" tone="border-t-accent" class="!shadow-none"
                @edit="emit('edit', $event)" @pin="emit('pin', $event)" @remove="emit('remove', $event)" @open-media="emit('open-media', $event)"
                @moderate="(p, a) => emit('moderate', p, a)" @report="emit('report', $event)" />

            <Reactions :board-id="board.id" :post="post" :can="can.interact" :manage="can.manage" />

            <GradePanel :post="post" :board="board" :manage="can.manage" :quick="quickList" @quick-update="quickList = $event" />

            <section v-if="board.allow_comments || total">
                <h3 class="mb-3 font-bold">💬 Komentar <span class="text-muted">({{ total }})</span></h3>
                <p v-if="loading" class="text-sm text-muted">Memuat…</p>
                <p v-else-if="!total" class="text-sm text-muted">Belum ada komentar.</p>

                <ul class="space-y-3">
                    <li v-for="c in comments" :key="c.id">
                        <div class="rounded-2xl bg-surface2/70 px-3 py-2">
                            <p class="flex items-center gap-2 text-xs font-bold text-muted">
                                <span :class="c.is_teacher ? 'text-ink' : ''">{{ c.author }}</span><span v-if="c.is_teacher">· guru</span>
                                <span>· {{ timeAgo(c.created_at) }}</span>
                                <span v-if="c.status !== 'published'" class="rounded bg-warning/20 px-1.5 text-warning">menunggu persetujuan</span>
                            </p>
                            <p class="whitespace-pre-line break-words">{{ c.body }}</p>
                            <div class="mt-1 flex gap-3 text-xs font-bold text-muted">
                                <button v-if="canComment" type="button" class="hover:text-ink" @click="replyTo = c">Balas</button>
                                <button v-if="c.can_delete" type="button" class="hover:text-danger" @click="removeComment(c)">Hapus</button>
                            </div>
                        </div>
                        <ul v-if="c.replies.length" class="mt-2 ml-6 space-y-2 border-l-2 border-line pl-3">
                            <li v-for="r in c.replies" :key="r.id" class="rounded-2xl bg-surface2/50 px-3 py-2">
                                <p class="flex items-center gap-2 text-xs font-bold text-muted">
                                    <span :class="r.is_teacher ? 'text-ink' : ''">{{ r.author }}</span><span v-if="r.is_teacher">· guru</span>
                                    <span>· {{ timeAgo(r.created_at) }}</span>
                                    <span v-if="r.status !== 'published'" class="rounded bg-warning/20 px-1.5 text-warning">menunggu persetujuan</span>
                                </p>
                                <p class="whitespace-pre-line break-words">{{ r.body }}</p>
                                <button v-if="r.can_delete" type="button" class="mt-1 text-xs font-bold text-muted hover:text-danger" @click="removeComment(r)">Hapus</button>
                            </li>
                        </ul>
                    </li>
                </ul>

                <form v-if="canComment" class="mt-4 space-y-2" @submit.prevent="send">
                    <p v-if="replyTo" class="flex items-center gap-2 text-sm text-muted">
                        Membalas <span class="font-bold text-ink">{{ replyTo.author }}</span>
                        <button type="button" class="text-xs font-bold hover:text-danger" @click="replyTo = null">✕ batal</button>
                    </p>
                    <div class="flex items-end gap-2">
                        <textarea v-model="body" rows="2" maxlength="1000" placeholder="Tulis komentar…" aria-label="Komentar"
                            class="min-w-0 flex-1 resize-none rounded-xl border-2 border-line bg-surface px-3 py-2 text-ink focus:border-accent focus:outline-none"
                            @keydown.enter.exact.prevent="send"></textarea>
                        <Btn type="submit" :loading="sending" :disabled="!body.trim()">Kirim</Btn>
                    </div>
                    <QuickChips v-if="can.manage && quick" :key="quickList.join('|')" :items="quickList" @pick="addQuick" @update="quickList = $event" />
                    <label v-if="board.allow_anonymous" class="flex items-center gap-2 text-sm font-semibold">
                        <input v-model="anonymous" type="checkbox" class="size-4 accent-[var(--m-accent)]" /> Kirim sebagai anonim
                    </label>
                </form>
                <p v-else-if="board.is_frozen || board.schedule === 'after'" class="mt-3 text-sm text-muted">{{ board.is_frozen ? '❄️ Papan dibekukan' : '🔒 Papan sudah ditutup' }}; komentar ditutup.</p>
            </section>
        </div>
    </Modal>
</template>
