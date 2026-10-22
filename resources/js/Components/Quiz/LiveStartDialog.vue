<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';

// Mulai kuis live (QZ-10, QZ-12, AUTH-07)
const props = defineProps({ show: Boolean, quiz: Object });
const emit = defineEmits(['close']);
const form = useForm({ seconds: 20, teams: 0, allow_guests: true, leaderboard: true, shuffle_questions: false });
watch(() => props.show, (v) => { if (v) { form.reset(); form.clearErrors(); } });
const submit = () => form.post(route('live.store', props.quiz.id));
const SECONDS = [10, 20, 30, 45, 60, 90, 120];
</script>

<template>
    <Modal :show="show" :title="`Mulai live: ${quiz?.title ?? ''}`" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <p class="mb-1.5 text-sm font-bold">Waktu per soal</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="n in SECONDS" :key="n" type="button" class="rounded-xl border-2 px-3 py-2 font-bold" :class="form.seconds === n ? 'border-accent bg-accent text-on-accent' : 'border-line'" :aria-pressed="form.seconds === n" @click="form.seconds = n">{{ n }} dtk</button>
                </div>
            </div>
            <div>
                <p class="mb-1.5 text-sm font-bold">Mode</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="n in [0, 2, 3, 4]" :key="n" type="button" class="rounded-xl border-2 px-3 py-2 font-bold" :class="form.teams === n ? 'border-accent bg-accent text-on-accent' : 'border-line'" :aria-pressed="form.teams === n" @click="form.teams = n">{{ n ? n + ' tim' : 'Perorangan' }}</button>
                </div>
                <p v-if="form.teams" class="mt-1 text-xs text-muted">Pemain dibagi rata otomatis; skor tim = rata-rata skor anggota.</p>
            </div>
            <label class="flex items-start gap-3"><input v-model="form.allow_guests" type="checkbox" class="mt-1 size-5 accent-[var(--m-accent)]" /><span><b>Izinkan tamu tanpa akun</b><br /><span class="text-sm text-muted">Ikut cukup dengan kode + nama. Tamu hanya bisa membuka kuis ini, dan guru bisa mengeluarkan nama yang tidak pantas.</span></span></label>
            <label class="flex items-start gap-3"><input v-model="form.leaderboard" type="checkbox" class="mt-1 size-5 accent-[var(--m-accent)]" /><span><b>Tampilkan papan peringkat</b> setiap selesai soal</span></label>
            <label class="flex items-start gap-3"><input v-model="form.shuffle_questions" type="checkbox" class="mt-1 size-5 accent-[var(--m-accent)]" /><span><b>Acak urutan soal</b></span></label>
            <p class="text-sm text-muted">Skor = ketepatan + kecepatan. Nilai (0–100) tetap dari ketepatan dan masuk laporan kuis.</p>
            <Btn type="submit" block size="lg" :loading="form.processing">Buka lobi ▶</Btn>
        </form>
    </Modal>
</template>
