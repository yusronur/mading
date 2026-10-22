<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/Btn.vue';
import DateField from '@/Components/DateField.vue';
import { toLocalInput } from '@/lib/datetime';

// Tugaskan kuis mandiri ke satu/lebih rombel dengan tenggat (QZ-09).
const props = defineProps({ show: Boolean, quiz: Object, classes: Array });
const emit = defineEmits(['close']);
const form = useForm({ public: false, allow_guests: false, duration_minutes: 30, class_ids: [], opens_at: '', closes_at: '' });

watch(() => props.show, (v) => {
    if (!v) return;
    const due = new Date(Date.now() + 7 * 86400e3);
    due.setHours(23, 59, 0, 0);
    form.defaults({ public: false, allow_guests: false, duration_minutes: props.quiz?.settings?.duration_minutes || 30, class_ids: [], opens_at: '', closes_at: toLocalInput(due) }).reset();
    form.clearErrors();
});

const minDate = toLocalInput(new Date()).slice(0, 10);
const toggle = (id) => (form.class_ids = form.class_ids.includes(id) ? form.class_ids.filter((x) => x !== id) : [...form.class_ids, id]);
// Kirim sebagai waktu server (WIB) "YYYY-MM-DD HH:mm"
const submit = () => form.transform((d) => ({ ...d, public: d.public || null, allow_guests: d.public && d.allow_guests ? true : null, opens_at: d.opens_at ? d.opens_at.replace('T', ' ') : null, closes_at: d.closes_at.replace('T', ' ') }))
    .post(route('assignments.store', props.quiz.id), { preserveScroll: true, onSuccess: () => emit('close') });
</script>

<template>
    <Modal :show="show" :title="`Tugaskan: ${quiz?.title ?? ''}`" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3" :class="form.public ? 'border-accent bg-accent/10' : 'border-line'">
                <input v-model="form.public" type="checkbox" class="mt-1 h-5 w-5 accent-[var(--accent,#2563eb)]" />
                <span>
                    <span class="block font-bold">Kuis publik (kuis cepat)</span>
                    <span class="block text-sm text-muted">Tidak terikat rombel: siapa pun siswa sekolah yang memasukkan kode kuis bisa mengerjakan. Bisa dipakai bersamaan dengan rombel di bawah.</span>
                </span>
            </label>
            <label v-if="form.public" class="-mt-2 flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3" :class="form.allow_guests ? 'border-accent2 bg-accent2/10' : 'border-line'">
                <input v-model="form.allow_guests" type="checkbox" class="mt-1 h-5 w-5 accent-[var(--accent,#2563eb)]" />
                <span>
                    <span class="block font-bold">Izinkan tamu tanpa akun</span>
                    <span class="block text-sm text-muted">Peserta cukup memasukkan kode + nama (seperti Kahoot), tanpa login. Tamu hanya bisa membuka kuis ini. Maksimal 300 tamu per kuis.</span>
                </span>
            </label>
            <div>
                <p class="mb-1.5 text-sm font-bold">Rombel <span v-if="form.public" class="font-normal text-muted">(opsional)</span></p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in classes" :key="c.id" type="button" class="rounded-xl border-2 px-3 py-2 font-bold"
                        :class="form.class_ids.includes(c.id) ? 'border-accent bg-accent text-on-accent' : 'border-line'" :aria-pressed="form.class_ids.includes(c.id)" @click="toggle(c.id)">{{ c.name }}</button>
                </div>
                <p v-if="!classes.length" class="text-sm text-muted">Belum ada rombel di tahun ajaran aktif.</p>
                <p v-if="form.errors.class_ids" class="mt-1 text-sm font-semibold text-danger">{{ form.errors.class_ids }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-bold" for="dur">Durasi mengerjakan (menit) <span class="text-danger">*</span></label>
                <input id="dur" v-model.number="form.duration_minutes" type="number" min="1" max="600" required class="h-11 w-28 rounded-xl border-2 border-line bg-surface px-3 text-ink" />
                <p class="mt-1 text-xs text-muted">Dihitung sejak siswa menekan Mulai, dan diulang penuh di tiap percobaan. Tenggat di bawah hanya membatasi kapan siswa boleh mulai.</p>
                <p v-if="form.errors.duration_minutes" class="text-sm font-semibold text-danger">{{ form.errors.duration_minutes }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <DateField v-model="form.opens_at" mode="datetime" label="Dibuka (kosong = sekarang)" placeholder="Langsung dibuka" :quick="['06:00', '07:00', '08:00', '12:00']" :min="minDate" />
                <DateField v-model="form.closes_at" mode="datetime" label="Tenggat" required :error="form.errors.closes_at" :min="minDate" />
            </div>
            <p class="text-sm text-muted">Soal dan pengaturan disalin saat ditugaskan. Mengubah kuis nanti tidak mengubah tugas yang sudah berjalan.</p>
            <Btn type="submit" block size="lg" :loading="form.processing" :disabled="!form.class_ids.length && !form.public">{{ form.public && !form.class_ids.length ? 'Buat kuis publik' : `Tugaskan ke ${form.class_ids.length || ''} rombel` }}</Btn>
        </form>
    </Modal>
</template>
