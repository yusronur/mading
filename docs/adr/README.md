# Catatan Keputusan Arsitektur (ADR)

Format: lihat Blueprint Lampiran B. Satu keputusan satu bagian; keputusan lama tidak
dihapus, hanya diberi status "diganti oleh ADR-xxx".

| No | Keputusan | Status |
|---|---|---|
| ADR-001 | Laravel 12 + Inertia 3 + Vue 3 + Tailwind 4 | Diterima (21-10-2026) |
| ADR-002 | PostgreSQL, bukan MySQL | Diterima |
| ADR-003 | Multi-tenant satu database, `school_id` di semua tabel bisnis | Diterima |
| ADR-004 | Penentu tenant: `single` (kini) lalu `domain` (subdomain + domain kustom) | Diterima, diperbarui |
| ADR-005 | Status kuis live di Redis, tulis batch ke PostgreSQL | Diterima (berlaku Fase 2) |
| ADR-006 | Layanan real-time terpisah (Reverb) | Diterima (berlaku Fase 2) |
| ADR-007 | Berkas per sekolah lewat abstraksi Storage | Diterima (berlaku Fase 1) |
| ADR-008 | Token tema berbasis CSS variable | Diterima |
| ADR-009 | UUID v7 untuk semua kunci utama | Diterima |
| ADR-010 | Gamifikasi berbasis event | Diterima (berlaku Fase 2) |
| ADR-011 | Peran database ganda: pemilik untuk migrasi, aplikasi non-superuser | Diterima (21-10-2026) |
| ADR-012 | Pengembangan di XAMPP/Apache, deploy ke aaPanel/Apache; Docker opsional | Diterima (21-10-2026) |

---

## ADR-001: Laravel + Inertia + Vue + Tailwind

**Konteks.** Pengembang tunggal paruh waktu yang menguasai PHP dan PostgreSQL. Fitur
membutuhkan interaksi kaya (drag kartu, canvas, kuis live) dan real-time.

**Pilihan.** (1) PHP native, (2) Laravel + Livewire, (3) Laravel + Inertia + Vue, (4) Node.js penuh.

**Keputusan.** Laravel 12 + Inertia 3 + Vue 3 + Tailwind 4.

**Alasan.** Satu bahasa server yang sudah dikuasai; migrasi, antrean, otorisasi, dan
WebSocket (Reverb) tersedia dalam satu ekosistem; Vue cocok untuk interaksi papan dan kuis.
Laravel 12 dipilih karena server memakai PHP 8.2.

**Konsekuensi.** Node.js dibutuhkan di laptop untuk build. Naik ke Laravel 13 menunggu
server memakai PHP 8.3.

## ADR-004 (diperbarui): penentu tenant

**Keputusan.** `TENANT_MODE=single` memakai `TENANT_DEFAULT_SLUG`. `TENANT_MODE=domain`
mencocokkan host ke tabel `school_domains` (domain kustom, mis.
`mading.smpn1wajak.sch.id`), lalu subdomain dari `TENANT_BASE_DOMAIN`
(mis. `smpn1wajak.madingo.id`). Kode fitur tidak berubah saat berganti mode.

## ADR-009: UUID v7

**Keputusan.** Semua kunci utama memakai UUID v7 (trait `HasUuids` Laravel 12):
tidak bisa ditebak di URL, tetap terurut waktu sehingga ramah indeks.

## ADR-011: peran database ganda

**Konteks.** Superuser dan pemilik tabel PostgreSQL melewati Row-Level Security. Bila
aplikasi tersambung sebagai `postgres`, RLS tidak melindungi apa pun.

**Keputusan.** Aplikasi tersambung sebagai `madingo_app` (LOGIN, NOSUPERUSER, NOBYPASSRLS,
bukan pemilik tabel). Migrasi dan seeder memakai koneksi `pgsql_owner`. Hak akses tabel
diberikan otomatis setiap migrasi selesai.

**Konsekuensi.** `.env` berisi dua akun. `php artisan migrate` dan `db:seed` otomatis
memakai koneksi pemilik. Pengujian memverifikasi bahwa koneksi aplikasi bukan superuser.
Saat PgBouncer dipasang (mode transaction), `set_config` perlu diubah ke `SET LOCAL`
per transaksi.

## ADR-012: XAMPP ke aaPanel

**Keputusan.** Pengembangan di XAMPP (Windows, Apache), produksi di aaPanel (Apache).
Redis, PgBouncer, dan Docker bersifat opsional; di laptop antrean berjalan `sync`.

**Konsekuensi.** Perhatikan beda huruf besar-kecil nama berkas (Windows vs Linux) dan
akhir baris (`.gitattributes` memaksa LF).

## ADR-013: tanpa dialog bawaan peramban

**Keputusan.** Aplikasi tidak memakai `alert()`, `confirm()`, atau `prompt()`. Konfirmasi
memakai `askConfirm()` (`resources/js/lib/confirm.js`, komponen `ConfirmDialog`), pemberitahuan
singkat memakai `toast()` (`lib/toast.js`, komponen `Flash`). Pesan flash dari server tampil
lewat toast yang sama. Pengujian `NoNativeDialogsTest` menggagalkan build bila ada yang lolos.

## ADR-014: pembaruan papan lewat pemeriksaan versi (sebelum WebSocket)

**Konteks.** Polling penuh 10 detik terasa lambat; SSE menahan satu proses PHP per tab di
Apache/XAMPP; Reverb baru direncanakan di Fase 2.

**Keputusan.** Tab papan menanyakan penanda versi (`GET /papan/{id}/sinkron`, satu query
agregat) tiap 2 detik, melebar bertahap sampai 6 detik bila sepi, berhenti saat tab
tersembunyi. Kartu dimuat ulang (`router.reload`) hanya bila penanda berubah. Dibatasi
90 permintaan/menit/pengguna.

**Konsekuensi.** Jeda pembaruan 2-3 detik, tanpa proses tambahan di server. Beban kira-kira
15 permintaan ringan/detik untuk satu kelas 30 siswa. WebSocket menggantikan ini bila
jumlah siswa serentak pada satu papan bertambah banyak.

## ADR-015: sandi awal tersimpan terenkripsi sampai diganti

**Keputusan.** `users.initial_password` (cast `encrypted`, kunci APP_KEY) menyimpan sandi awal
selama `must_change_password` bernilai true. Kolom dikosongkan saat pengguna mengganti sandi.
Admin membuat sandi awal untuk siswa yang belum pernah mengganti sandi (per rombel atau per
siswa) dan mencetaknya sebagai kartu; hanya id pengguna yang dikirim lewat sesi, bukan sandinya.

**Konsekuensi.** Kartu bisa dicetak ulang kapan saja tanpa me-reset sandi. Kehilangan APP_KEY
berarti sandi awal yang tersimpan tidak terbaca (cukup dibuat ulang). Sandi yang sudah diganti
siswa tidak pernah bisa dilihat admin.

## ADR-016: kuis disalin saat ditugaskan, dinilai di server

**Keputusan.** Saat kuis ditugaskan ke rombel, soal (teks, opsi, kunci, poin, media) dan pengaturan
disalin ke `quiz_sessions.questions` / `settings`. Jawaban dinilai di server setiap kali disimpan;
peramban siswa hanya menerima soal tanpa kunci. Batas waktu dihitung server (mulai + durasi, dibatasi
tenggat) dengan toleransi 5 detik; percobaan yang lewat waktu dikumpulkan otomatis saat diakses.

**Konsekuensi.** Guru bebas mengubah bank soal tanpa merusak nilai yang sudah berjalan. Perubahan
kuis berlaku untuk penugasan berikutnya. Kuis yang sudah dikerjakan tidak bisa dihapus.


## ADR-017: soal menjodohkan, bank publik, pantauan langsung

**Keputusan.** (1) Soal `match` dinilai sebagian: skor = pasangan benar / total x poin; "benar" hanya bila semua pasangan benar. Perbandingan memakai teks kolom kanan, kartu kanan dikocok server untuk siswa dan kuncinya tidak dikirim. (2) `question_banks.is_public` (bawaan privat): guru lain hanya bisa membaca, memilih soal ke kuisnya, atau menyalin bank (salinan privat milik penyalin, media ikut disalin). (3) Pantauan guru memakai polling ringan `GET /tugas-kuis/{sesi}/progres` tiap 3 detik, berhenti saat tab tersembunyi, tanpa analisis butir.

**Konsekuensi.** Pantauan tidak butuh WebSocket sampai Fase 2; beban bergantung jumlah guru yang membuka laporan, bukan jumlah siswa. Impor Excel/Word belum mendukung soal menjodohkan.

## ADR-018: tamu kuis sebagai akun sementara yang terkunci

**Keputusan.** Tamu (kode + nama) dibuat sebagai `users.role = guest` dengan sandi acak tak dikenal, hanya bila guru
mencentang izin tamu pada tugas publik. `ConfineGuests` menolak semua rute selain mengerjakan kuis, dan `QuizEngine::canTake`
mengikat tamu ke satu sesi (`profile.guest_session`). Memakai akun nyata menjaga seluruh mesin kuis, RLS, dan laporan
tanpa jalur kode kedua. Batas: 60 permintaan/menit/IP dan 300 tamu per kuis.

**Konsekuensi.** Tamu yang menghapus cookie bisa masuk lagi dengan nama baru (kesempatan per-tamu tidak mengikat orang);
cocok untuk kuis cepat, bukan ujian. Akun yang tidak dipakai dibersihkan harian.

## ADR-019: status kuis live di PostgreSQL, bukan Redis

**Keputusan.** Fase permainan (lobi, soal, pembahasan, peringkat, podium), nomor soal, batas waktu, dan versi
disimpan di `quiz_sessions.live` (jsonb). Perpindahan fase memakai transaksi + kunci baris. Pemain adalah
`quiz_attempts` (dengan `team`), jawaban di `quiz_answers` (dengan `live_score`, `elapsed_ms`). Soal yang lewat
batas waktu ditutup secara malas oleh permintaan status/jawaban berikutnya, tanpa pekerja antrean.

**Alasan.** Skala satu sekolah (puluhan pemain per sesi) jauh di bawah batas PostgreSQL; Redis belum tersedia di
XAMPP dan menambah satu layanan lagi di aaPanel. Laporan, analisis butir, dan ekspor kuis mandiri langsung
terpakai tanpa sinkronisasi batch. Mengubah ADR-005 untuk Fase 2; Redis ditinjau ulang saat go public.

**Konsekuensi.** Setiap jawaban = satu INSERT + dua COUNT. Uji beban (k6) di akhir Fase 2 menentukan apakah
perlu Redis.

## ADR-020: Reverb hanya menyiarkan sinyal; status selalu lewat HTTP

**Keputusan.** Laravel Reverb (Pusher protocol) di kanal privat `school.{s}.live.{id}`. Siaran berisi `{kind, v}`
saja (`state`, `players`, `progress` + hitungan). Klien yang menerima `state` mengambil `GET /live/{id}/status`;
kunci jawaban hanya ada di respons setelah soal ditutup. Jawaban dikirim lewat HTTP (validasi + rate limit
yang sama). Client events dimatikan (`accept_client_events_from=none`). Bila siaran gagal, siaran dilewati 30
detik dan klien memakai pengecekan berkala (2 detik bila WebSocket putus, 12 detik bila tersambung).

**Konsekuensi.** Tidak ada data sensitif yang lewat WebSocket; Reverb mati tidak menghentikan kuis. Sinyal
`progress` tidak memicu pengambilan status oleh pemain, jadi 40 jawaban tidak menjadi 1.600 permintaan.
