# Changelog

Format mengikuti Blueprint Bab 17.2. Versi memakai SemVer (`0.x` selama Fase 0-3).

## [0.1.4] - 2026-10-22

Tanpa migrasi; jalankan `npm run build`.

- Admin bisa menambah guru atau siswa satu per satu tanpa impor (Pengguna, tombol "Tambah pengguna"):
  NISN/username, nama, jenis kelamin, rombel (siswa wajib; guru opsional sebagai wali kelas). Sandi awal
  dibuat otomatis, tampil di dialog dan bisa dicetak sebagai kartu akun. Username kembar ditolak.

## [0.1.3] - 2026-10-22

Tanpa migrasi; jalankan `npm run build`.

- Tamu kuis (seperti Kahoot): pada tugas publik, centang "Izinkan tamu tanpa akun". Peserta membuka `/tamu`
  (atau tautan/QR kuis), mengisi kode + nama, lalu mengerjakan tanpa login. Akun tamu sementara, terkunci ke
  satu kuis (semua halaman lain ditolak), maksimal 300 tamu per kuis (`QUIZ_MAX_GUESTS`), nama kembar diberi
  nomor, tampil sebagai "Tamu" di laporan. Perintah `madingo:cleanup-guests` (terjadwal 02.45).
- Durasi pengerjaan wajib diisi (di pengaturan kuis dan di dialog penugasan). Waktu dihitung sejak siswa menekan
  Mulai dan diulang penuh di setiap percobaan; tenggat hanya membatasi kapan boleh mulai. Penyebab timer
  "185:30:50": kuis lama tanpa durasi menghitung mundur ke tenggat.

## [0.1.2] - 2026-10-22

- Perbaikan: halaman laporan kuis error ("Attempt to read property last on null") bila ada siswa yang sudah
  memulai tetapi belum menjawab satu soal pun.
- Kuis publik (kuis cepat): saat menugaskan, centang "Kuis publik"; tidak terikat rombel, siswa sekolah mana pun
  yang memasukkan kode bisa mengerjakan. Peserta muncul di laporan setelah memulai, dan kuisnya tampil di
  beranda mereka. Bisa digabung dengan penugasan ke rombel. Tanpa migrasi.

## [0.1.1] - 2026-10-23 (revisi uji coba Fase 1)

Migrasi: jalankan `php artisan migrate` (kolom `question_banks.is_public`), lalu `npm run build`.

- Notifikasi "kartu terkirim" hanya muncul di layar pengirim; kartu dan sematan baru dari orang lain muncul
  dengan animasi geser. Komentar baru di kartu sendiri memunculkan "Komentar baru di kartumu" dengan tombol Lihat.
- Kuis bisa dibuka dengan kode atau tautan (dialog bagikan kode + QR di Laporan dan daftar tugas).
- Tombol "Kembali ke ..." di halaman anak.
- Urutan soal di bank: manual (geser atau ketik nomor), terbaru, terlama.
- Jenis soal baru: Menjodohkan (seret di desktop, ketuk-pilih di HP; nilai sebagian per pasangan).
- Pantauan langsung guru: kemajuan, skor sementara, dan status siswa tanpa muat ulang halaman.
- Bank soal publik (centang "bisa dipakai guru lain", bawaan privat); pencarian bank dan soal di pemilih;
  guru lain bisa memakai atau menyalin bank publik.
- Pemilih tanggal/waktu buatan sendiri (tanpa input tanggal bawaan peramban).
- Jendela soal baru tidak tertutup saat klik di luar kotak.
- Cetak bank soal (ringkas) dengan pilihan menampilkan kunci.

## [0.1.0] - 2026-10-22 (Fase 1: MVP)

Migrasi: jalankan `php artisan migrate`, lalu `npm install` dan `npm run build`.

### Tahap 5 - format papan
- Format Wall, Grid, Stream, Shelf; ganti format kapan saja tanpa kehilangan kartu (BRD-02..05, BRD-11).
- Kolom Shelf: tambah, ganti nama, geser, hapus (kartu dipindah, tidak hilang).
- Urutan kartu: terbaru, terlama, manual; geser kartu (mouse dan layar sentuh) antar posisi dan antar kolom (BRD-13).
- Sematkan kartu di atas (PST-14).
- Pilihan format saat membuat papan; bilah format/urutan cepat untuk guru.
- Migrasi: `board_sections`, `posts.section_id`.

### Tahap 6 - isi kartu
- Editor teks berformat (tebal, miring, garis bawah, coret, daftar, kutipan, tautan); HTML dibersihkan
  server dengan daftar putih (PST-01).
- Lampiran gambar (otomatis WebP maks. 1600 px + thumbnail, putar sesuai EXIF), PDF, dokumen Office,
  audio, video; penampil layar penuh; berkas hanya bisa dibuka anggota papan (PST-02, PST-03).
- Pratinjau tautan dan sematan YouTube (dimuat saat diketuk), gambar pratinjau disimpan di server
  sekolah; pengamanan SSRF (PST-04).
- Warna kartu; ubah kartu oleh penulis (selama papan tidak dibekukan) dan oleh guru; tanda "diubah" (PST-13).
- Kuota penyimpanan dan batas unggahan per sekolah (ADM-07, nilai bawaan di `config/madingo.php`).
- Perintah `madingo:cleanup-attachments` (terjadwal 02.30) untuk lampiran yang batal dipakai.
- Migrasi: `attachments`, `posts.link`, `posts.edited_at`; isi kartu lama diubah ke HTML aman.

### Tahap 7 - komentar dan reaksi
- Komentar berutas satu tingkat (balas, hapus; guru menghapus semua), bisa ditutup per papan (INT-01).
- Reaksi per papan: suka, bintang 1-5, vote naik/turun, atau nilai angka dari guru yang hanya terlihat
  oleh guru dan penulis kartu (INT-02).
- Komentar anonim mengikuti pengaturan papan; guru tetap melihat nama asli (INT-05).
- Penanda versi sinkron memakai updated_at bermikrodetik agar perubahan beruntun tidak terlewat.
- Migrasi: `comments`, `reactions`, presisi `updated_at`.

### Tahap 8 - moderasi
- Persetujuan guru untuk kartu dan komentar siswa (opsional per papan); antrean Moderasi (MOD-01).
- Filter kata kasar Bahasa Indonesia: slang, singkatan, angka pengganti huruf, huruf berulang, diselipi
  titik/spasi; kata tambahan dan pengecualian diatur admin di Pengaturan. Teks yang kena ditahan, bukan
  ditolak (MOD-02).
- Sembunyikan/tampilkan/tolak kartu; siswa melaporkan kartu, 3 laporan otomatis menahan kartu (MOD-03).
- Visibilitas "tautan/kode rahasia" dengan tautan `/p/{token}` (MOD-04).
- Hak pengunjung: baca, tulis, atau tulis + ikut memoderasi (MOD-05).
- Batas kartu per siswa per papan (MOD-08).
- Pengaturan sekolah: kuota penyimpanan dan batas unggahan (ADM-07), daftar kata filter.
- Migrasi: `post_reports`, `board_members`, kolom moderasi, `boards.secret_token`.

### Tahap 9 - dasbor guru, kolaborator, bagikan, ekspor
- Dasbor guru: tab Papan saya / Dibagikan ke saya / Arsip, folder pribadi, cari, saring rombel dan format (BRD-16).
- Ikon dan latar papan, arsipkan dan pulihkan (papan arsip hanya bisa dibaca) (BRD-01).
- Duplikat papan, hanya struktur atau beserta kartu dan lampirannya; menyalin kartu hanya untuk pengelola (BRD-14).
- Kolaborator guru (co-admin): bisa mengelola isi dan pengaturan, tidak bisa menghapus papan atau mengubah kolaborator (BRD-19).
- Bagikan: QR, tautan `/gabung/KODE`, salin kode (SHR-01, SHR-02).
- Ekspor Excel (kartu + komentar, nama penulis asli), CSV, cetak/PDF, dan gambar PNG (SHR-04, SHR-05).
- Migrasi: `board_folders`, `boards.icon`, `boards.background`, `boards.folder_id`.

### Tahap 10 - login QR, wali kelas, ingat saya, log aktivitas
- Kartu QR login per siswa: pindai dengan kamera HP langsung masuk; token disimpan sebagai hash +
  salinan terenkripsi untuk cetak ulang; buat ulang/cabut per siswa atau per rombel (AUTH-03, ADM-09).
- Kartu cetak A4 berisi QR dan/atau sandi awal; siswa yang masuk lewat QR tidak dipaksa mengganti sandi.
- Wali kelas mendapat menu "Kelas Saya": buat QR, sandi awal, reset sandi siswa yang lupa, cetak kartu,
  hanya untuk rombel perwaliannya (AUTH-04).
- "Tetap masuk di perangkat ini" berlaku 30 hari (`AUTH_REMEMBER_DAYS`) (AUTH-06).
- Halaman Log aktivitas untuk admin dengan saringan jenis dan pencarian; tambah catatan papan dihapus,
  diarsipkan, kolaborator dilepas, login QR, QR dibuat/dicabut (ADM-08).
- Migrasi: `users.qr_token`, `users.qr_token_hash`, `users.qr_token_created_at`.

### Tahap 11 - bank soal
- Bank soal pribadi guru per mapel/kelas, saring tipe, tingkat kesulitan, topik, dan cari teks (QZ-01).
- Tipe soal: pilihan ganda, pilihan ganda kompleks, benar/salah, isian singkat (beberapa kunci;
  huruf besar/kecil, spasi, titik akhir, dan koma desimal diabaikan) (QZ-03, QZ-04).
- Media soal: gambar, audio, video (maks. 3); rumus KaTeX dengan `$...$` / `$$...$$`, pratinjau langsung (QZ-06).
- Impor dari templat Excel dan Word dengan pratinjau dan pesan per baris; Word mendukung penomoran otomatis
  dan gambar yang ditempel di soal (QZ-07). Templat bisa diunduh dari dialog impor.
- Kunci jawaban disimpan di server; tampilan siswa tidak pernah memuat kunci (dasar QZ-18).
- Migrasi: `question_banks`, `questions`.

### Tahap 12 - kuis mandiri dan laporan
- Kuis disusun dari bank soal: urutan, poin per soal, acak soal dan pilihan, batas waktu, jumlah kesempatan,
  nilai terbaik/terakhir, kapan pembahasan tampil, tampil/sembunyikan nilai, KKM (QZ-08).
- Tugaskan ke satu atau beberapa rombel dengan waktu buka dan tenggat; kode 6 karakter yang sama dengan
  kotak "Masukkan kode" papan (QZ-09, SHR-02).
- Lembar kerja siswa: satu soal per layar, simpan otomatis tiap jawaban (antre saat koneksi putus),
  waktu mengikuti jam server, kumpul otomatis saat waktu habis, pilihan bertanda huruf + bentuk.
- Penilaian sepenuhnya di server; kunci dan pembahasan tidak dikirim sebelum dibuka; soal disalin
  (snapshot) saat ditugaskan sehingga mengubah bank soal tidak mengubah nilai (QZ-18, ADR-016).
- Laporan guru: nilai per siswa, durasi, berapa kali keluar halaman, lihat jawaban siswa, izinkan ulang,
  analisis butir (indeks p dan sebaran jawaban), daftar remedial, tutup/perpanjang tenggat (QZ-14).
- Ekspor nilai dan analisis butir ke Excel (QZ-15). Beranda siswa menampilkan kuis untuknya.
- Migrasi: `quizzes`, `quiz_questions`, `quiz_sessions`, `quiz_attempts`, `quiz_answers`.

### Diperbaiki
- Jam tersimpan bergeser 7 jam bila zona waktu PostgreSQL berbeda dari aplikasi (mis. server UTC).
  Sesi database kini memakai zona aplikasi (`DB_TIMEZONE`, bawaan = `APP_TIMEZONE`). Ada tes pengamannya.

## [0.0.2] - 2026-10-21 (revisi uji coba)

### Ditambahkan
- Pengaturan papan lengkap (judul, petunjuk, tema + kunci tema, urutan kartu baru, akses/rombel,
  hak siswa, kartu anonim, bekukan, ganti kode, hapus papan).
- Kartu akun: sandi awal per rombel/per siswa untuk siswa yang belum pernah mengganti sandi,
  tersimpan terenkripsi sampai diganti, halaman cetak A4 (ADR-015). Menu baru "Kartu Akun".
- Dialog sandi awal setelah Reset sandi tidak hilang sendiri; ada tombol salin dan cetak kartu.
- Toast CSS global (`toast()`), dialog konfirmasi CSS (`askConfirm()`); tes menjaga agar tidak ada
  `alert/confirm/prompt` bawaan peramban (ADR-013).

### Diubah
- Pembaruan papan: pemeriksaan versi tiap 2 detik (sebelumnya polling penuh 10 detik), melebar saat sepi,
  berhenti saat tab tersembunyi; tema/judul/pembekuan dari guru ikut ter-update (ADR-014).
- Salin kode papan memakai cara cadangan untuk http (non-HTTPS) dan menampilkan toast "tersalin".

### Diperbaiki
- Kartu yang hanya berisi isi (tanpa judul) membuat galat 500.

### Migrasi
- `php artisan migrate` (menambah kolom `users.initial_password`).

## [0.0.1] - 2026-10-21 (Fase 0: Fondasi)

### Ditambahkan
- Kerangka Laravel 12 + Inertia 3 + Vue 3 + Tailwind 4, siap XAMPP dan aaPanel.
- Multi-tenant: tabel `schools`, `school_domains`, penentu sekolah mode `single` dan `domain`,
  global scope `BelongsToSchool`, Row-Level Security PostgreSQL di semua tabel bisnis,
  peran database aplikasi non-superuser (ADR-011).
- Perintah `madingo:install` (database, peran, migrasi, sekolah, admin, data demo) dan
  `madingo:sample-import` (data uji fiktif).
- Login guru/siswa dengan NISN/username, pembatasan percobaan + log penguncian,
  wajib ganti sandi pertama, akun nonaktif otomatis keluar (AUTH-01, 02, 05).
- Admin console: pengaturan sekolah, tahun ajaran, rombel + wali kelas, daftar pengguna,
  reset sandi, aktif/nonaktif (ADM-03, 05).
- Impor guru dan siswa dari Excel/CSV/ekspor Dapodik dengan pratinjau, validasi per baris,
  pembaruan tanpa duplikasi, sandi awal acak yang bisa diunduh lalu dihapus (ADM-01, 02).
- Feature flag per sekolah (ADM-06), log audit (ADM-08, sebagian).
- Token tema Neon Gelap dan Pastel Cerah (varian terang/gelap), pilihan tema siswa,
  mode warna, ukuran huruf, kurangi animasi, kunci tema per papan (THM-01, 02, 03, 06).
- Irisan tipis papan: guru membuat papan (format Wall), siswa bergabung dengan kode,
  menempel kartu teks, menghapus kartu sendiri; post anonim tidak membocorkan penulis.
- Pengujian otomatis: isolasi tenant (Eloquent, SQL mentah, tulis lintas sekolah, domain),
  login, hak akses peran, impor, papan, kontras tema.
