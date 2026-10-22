<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Field from '@/Components/Field.vue';
import Btn from '@/Components/Btn.vue';

const props = defineProps({ settings: Object, themes: Array, storage: Object, php_upload_limit: String, default_words: Number, push_ready: Boolean });
const pct = Math.min(100, Math.round((props.storage.used / props.storage.quota) * 100));
const form = useForm({ ...props.settings });
const submit = () => form.put(route('admin.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Pengaturan sekolah" />
    <AppLayout title="Pengaturan sekolah">
        <Card class="max-w-2xl">
            <form class="space-y-5" @submit.prevent="submit">
                <Field v-model="form.name" label="Nama sekolah" :error="form.errors.name" />
                <Field v-model="form.npsn" label="NPSN" :error="form.errors.npsn" inputmode="numeric" maxlength="8" />
                <Field v-model="form.app_display_name" label="Nama aplikasi di header" :error="form.errors.app_display_name"
                    hint="Contoh: Mading, Mading Kelas, Ruang Kelas. Tampil di samping nama sekolah." />
                <Field v-model="form.default_theme_id" as="select" label="Tema bawaan sekolah" :error="form.errors.default_theme_id"
                    hint="Dipakai bila siswa/guru belum memilih tema sendiri.">
                    <option :value="null">(Tema pertama)</option>
                    <option v-for="t in themes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </Field>
                <fieldset class="space-y-4 rounded-2xl border border-line p-4">
                    <legend class="px-2 text-sm font-bold text-muted uppercase">Penyimpanan</legend>
                    <div>
                        <div class="mb-1 flex justify-between text-sm font-semibold"><span>Terpakai {{ storage.used_label }}</span><span>{{ pct }}%</span></div>
                        <div class="h-3 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full" :class="pct > 90 ? 'bg-danger' : pct > 70 ? 'bg-warning' : 'bg-accent'" :style="{ width: pct + '%' }"></div></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Field v-model="form.storage_quota_mb" type="number" min="100" label="Kuota sekolah (MB)" :error="form.errors.storage_quota_mb" />
                        <Field v-model="form.max_upload_mb" type="number" min="1" label="Batas per berkas (MB)" :error="form.errors.max_upload_mb"
                            :hint="`Batas PHP server saat ini: ${php_upload_limit}. Naikkan upload_max_filesize & post_max_size di php.ini bila perlu.`" />
                    </div>
                </fieldset>
                <fieldset class="space-y-4 rounded-2xl border border-line p-4">
                    <legend class="px-2 text-sm font-bold text-muted uppercase">Filter kata kasar</legend>
                    <p class="text-sm text-muted">Sudah ada {{ default_words }} kata bawaan (termasuk singkatan dan slang). Kartu/komentar yang mengandung kata ini ditahan sampai disetujui guru.</p>
                    <Field v-model="form.bad_words" as="textarea" :rows="3" label="Tambahan kata terlarang" placeholder="pisahkan dengan koma" :error="form.errors.bad_words" />
                    <Field v-model="form.allowed_words" as="textarea" :rows="2" label="Kata bawaan yang diizinkan" placeholder="mis. bego (bila sering salah tangkap)" :error="form.errors.allowed_words" />
                </fieldset>
                <fieldset class="space-y-2 rounded-2xl border border-line p-4">
                    <legend class="px-2 text-sm font-bold text-muted uppercase">Gamifikasi</legend>
                    <label class="flex items-start gap-3 font-semibold">
                        <input v-model="form.gamify_enabled" type="checkbox" class="mt-1 size-5 accent-[var(--c-accent)]" data-testid="gamify-enabled" />
                        <span>Aktifkan XP, level, lencana, dan peringkat kelas untuk siswa
                            <span class="block text-sm font-normal text-muted">Dimatikan: siswa tidak mendapat XP baru dan menu Prestasi disembunyikan. Data lama tetap tersimpan.</span>
                        </span>
                    </label>
                </fieldset>
                <fieldset class="space-y-2 rounded-2xl border border-line p-4">
                    <legend class="px-2 text-sm font-bold text-muted uppercase">Notifikasi push</legend>
                    <label class="flex items-start gap-3 font-semibold">
                        <input v-model="form.push_enabled" type="checkbox" class="mt-1 size-5 accent-[var(--c-accent)]" data-testid="push-enabled" />
                        <span>Izinkan notifikasi push ke HP dan laptop pengguna
                            <span class="block text-sm font-normal text-muted">Dimatikan: pesan tetap masuk lonceng, tetapi tidak muncul di layar perangkat.</span>
                        </span>
                    </label>
                    <p v-if="!push_ready" class="rounded-xl bg-warning/15 p-3 text-sm" data-testid="vapid-warning">Kunci VAPID belum diatur di server, jadi push belum bisa dikirim. Jalankan <code>php artisan madingo:vapid</code>, tempel hasilnya ke <code>.env</code>, lalu <code>php artisan config:cache</code>.</p>
                </fieldset>
                <Btn type="submit" :loading="form.processing">Simpan pengaturan</Btn>
            </form>
        </Card>
    </AppLayout>
</template>
