# Changelog

Format mengikuti Blueprint Bab 17.2. Versi memakai SemVer (`0.x` selama Fase 0-3).

## [0.2.0-alpha.15] - 2026-10-23 — Tahap 20: kartu realtime + uji beban

### Ditambahkan
- ⚡ Kartu papan realtime lewat Reverb: kartu baru, ubah, hapus, pindah, sematan, reaksi, komentar, centang, nilai, kolom, dan pengaturan papan muncul di layar semua peserta tanpa jeda pengecekan (uji lokal: ±1 detik dari kirim sampai tampil di layar guru).
- Satu permintaan = satu sinyal per papan, dikirim setelah respons, membawa penanda versi; klien yang sudah mutakhir tidak memuat ulang, klien lain memuat ulang dengan jeda acak 0–0,6 detik agar server tidak diserbu serentak.
- Saat WebSocket tersambung, pengecekan `/sinkron` turun dari tiap 2 detik menjadi tiap 30 detik (jaring pengaman). Bila Reverb mati, otomatis kembali ke tiap 2 detik; tab tersembunyi tidak memuat apa pun dan langsung menyusul saat dibuka.
- Uji beban k6: `tests/load/board.js` (papan) dan `tests/load/live.js` (kuis live), keduanya memakai WebSocket seperti peramban; perintah `php artisan madingo:loadtest seed|clean` untuk akun uji `uji-beban-*`. Panduan di `tests/load/README.md` dan DEPLOY bagian 8.6–8.7.

### Diperbaiki
- Kanal WebSocket yang dipakai bersama (kartu + aktivitas papan) tidak lagi ikut terputus saat salah satu komponen ditutup.

## [0.2.0-alpha.14] - 2026-10-23 — Tahap 18: alat guru

### Ditambahkan
- 📊 Rubrik penilaian kartu (Pengaturan papan, maks. 6 kriteria). Guru menilai kartu siswa per kriteria + umpan balik; nilai 0–100 tampil sebagai lencana di kartu (hanya penulis dan guru). Siswa mendapat notifikasi "Kartumu sudah dinilai" (sekali per kartu) yang langsung membuka kartunya.
- 📊 Menu **Rekap Nilai** (guru & admin): satu tabel per rombel berisi nilai tugas kuis, kuis live, dan rubrik papan, rata-rata siswa dan kelas, tanda merah di bawah KKM, unduh Excel. Guru melihat miliknya; admin bisa semua guru.
- ⭐ Template papan: simpan papan (kolom, pengaturan, rubrik, kartu contoh guru) sebagai template pribadi atau dibagikan ke semua guru; pilih "Mulai dari template" saat membuat papan; tab Template di Papan Saya.
- 📤 Salin papan ke beberapa rombel sekaligus (mis. untuk tahun ajaran baru), dengan atau tanpa kartu buatan guru. Kartu siswa tidak ikut.
- ⏰ Jadwal buka-tutup papan: sebelum dibuka siswa belum melihat kartu; setelah ditutup papan hanya bisa dibaca. Status berubah otomatis tanpa muat ulang.
- 💬 Komentar cepat guru: deretan komentar siap pakai di kolom komentar dan umpan balik rubrik; bisa diatur sendiri (maks. 20).

### Migrasi
- Tabel `post_grades`; kolom `boards.is_template`, `boards.template_shared`, `user_preferences.quick_comments`.

## [0.2.0-alpha.13] - 2026-10-23 — Tahap 17: isi kartu

### Ditambahkan
- ✏️ Coretan: menggambar langsung di form kartu (8 warna, 3 ketebalan, penghapus, urungkan). Hasil disimpan sebagai gambar lampiran.
- 🎙️ Rekam suara di kartu, maksimal 3 menit, bisa didengar ulang sebelum dipakai. Rekaman WebM/Ogg dari peramban dikenali sebagai audio.
- ☑️ Daftar centang di kartu (maks. 30 butir) dengan bilah kemajuan. Centang langsung tersimpan. Penulis dan guru boleh mencentang; pengaturan papan "Semua anggota boleh mencentang" untuk tugas kelompok. Ikut diperiksa filter kata kasar dan ikut diekspor.
- 🔗 Sematan selain YouTube: Vimeo, Google Slides/Dokumen/Spreadsheet/Formulir/Drive, dan Canva. Dimuat hanya saat diketuk (hemat kuota); alamat sematan dibangun ulang dari pola ketat.

### Migrasi
- `posts.checklist` (jsonb).

## [0.2.0-alpha.12] - 2026-10-23

### Ditambahkan
- Peringkat kelas (guru): filter "XP dari kuis & papan saya" selain "Semua XP" (default tetap gabungan). Hanya menghitung XP dari kuis/sesi live yang ia host dan papan yang ia miliki.

### Diubah
- Tombol tampilkan/sembunyikan peringkat ke siswa kini hanya untuk admin sekolah (guru tidak bisa lagi).

## [0.2.0-alpha.11] - 2026-10-23

### Diperbaiki
- Lonceng notifikasi kini realtime: server mengirim sinyal ke kanal pribadi pengguna (Reverb) sehingga angka langsung naik tanpa refresh. Bila WebSocket tidak tersambung, dicek tiap 20 detik. Langganan tidak lagi dibuat ulang setiap pindah halaman.

### Ditambahkan
- Saran pasang aplikasi berupa banner kecil yang jarang muncul (kunjungan ke-3, sekali per sesi, jeda 14 hari, berhenti setelah ditutup 3 kali) dengan tombol tutup.
- Tombol "Pasang aplikasi" di menu profil (di atas Keluar); di iPhone/iPad membuka petunjuk langkah demi langkah.

### Diubah
- Halaman login: "Ikut kuis tanpa akun" kini tombol besar berwarna lain tepat di bawah tombol Masuk, dengan keterangan "cukup masukkan kode kuis".

## [0.2.0-alpha.10] - 2026-10-23

### Diperbaiki
- Kuis live yang ditinggalkan guru (tidak pernah ditekan Akhiri) kini ditutup otomatis setelah 3 jam tanpa aktivitas guru. Sebelumnya siswa yang membuka beranda masih melihat "Lanjutkan mengerjakan" dan terjebak di layar "Waktu habis!" tanpa jalan keluar. Penutupan terjadi saat siswa membuka beranda atau status kuis, dan lewat pekerjaan terjadwal tiap jam (`madingo:close-stale-live`).
- Layar pemain kuis live kini punya tombol beranda (🏠) di header pada semua fase; percobaan tetap tersimpan.

## [0.2.0-alpha.9] - 2026-10-22

### Ditambahkan (Tahap 19: PWA dan notifikasi)
- Lonceng notifikasi di header untuk admin, guru, dan siswa: angka belum dibaca, panel ringkas, halaman Notifikasi dengan riwayat 30 hari, tandai dibaca.
- Madingo bisa dipasang ke layar utama (PWA): manifest mengikuti nama sekolah, ikon, service worker, halaman "tidak ada koneksi". Data pengguna tidak pernah disimpan di cache perangkat.
- Web Push gratis tanpa layanan pihak ketiga (enkripsi RFC 8291 dan VAPID memakai OpenSSL bawaan PHP). Pengguna mengaktifkan per perangkat dan memilih jenis kabar yang dikirim.
- Aturan anti-banjir: maksimal 5 push per orang per hari, jam tenang 21.00-06.00 (ditahan, dikirim sebagai satu ringkasan pagi), digabung bila beberapa sekaligus, kuis live tidak dipush ke siswa yang sedang online.
- Push siswa: kuis atau tugas baru di rombelnya, pengingat tenggat H-1 (hanya yang belum mengumpulkan), nilai keluar setelah tugas ditutup, kuis live dimulai (guru memilih rombel di dialog Mulai live).
- Push guru: ringkasan pengumpulan harian. Admin: masalah sistem (mis. Reverb tidak terjangkau).
- Komentar, suka, pengumpulan, level dan lencana hanya masuk lonceng (tanpa push), digabung per kartu atau tugas.
- Admin: menu Pesan untuk mengirim pesan manual ke satu orang, semua guru, semua siswa, satu rombel, atau semua, dengan pratinjau jumlah penerima, konfirmasi, jangkauan push, dan riwayat.
- Pengaturan sekolah: saklar push. Perintah baru `madingo:vapid` (buat kunci) dan `madingo:notify` (pekerjaan terjadwal).

### Keamanan
- Endpoint langganan push hanya diterima dari layanan push resmi peramban (mencegah server dipakai menghubungi alamat sembarang).
- HP bersama: langganan berpindah ke akun yang sedang masuk, dan keluar akun melepas langganan perangkat itu.

## [0.2.0-alpha.8] - 2026-10-22

### Diperbaiki
- Timer: jeda/lanjut tidak lagi terlewat di layar siswa (sinyal yang datang saat pengambilan data berjalan kini diulang, dan layar yang menampilkan aktivitas mengecek tiap 4 detik sebagai cadangan).
- Spin wheel (tanpa pengulangan): sisa satu nama otomatis terpilih, tombol Putar disembunyikan; server menolak putaran untuk satu nama terakhir.
- Word cloud: tombol ✕ hapus hanya tampil saat kursor di atas kata.

## [0.2.0-alpha.7] - 2026-10-22

### Ditambah
- Reset sandi lewat email: tautan "Lupa sandi" di halaman masuk (tampil bila SMTP dikonfigurasi). Token acak sekali pakai, berlaku 60 menit, hanya hash disimpan; maksimal 3 permintaan per akun per jam; pesan sama untuk akun yang ada maupun tidak (tidak membocorkan daftar akun). Setelah sandi baru dibuat, semua sesi perangkat lain dikeluarkan.
- Pengguna bisa mengisi email sendiri di halaman Profil (butuh sandi saat ini); admin bisa mengisinya lewat tombol Edit.
- Admin: tombol **Edit** di menu Pengguna untuk mengubah nama, NISN/username, email, jenis kelamin, tanggal lahir, dan rombel siswa (tercatat di log aktivitas).
- Kartu akun: pilihan peran **Siswa / Guru**. Admin bisa membuat sandi awal dan mencetak kartu kolektif seluruh guru (kartu guru tanpa QR). Wali kelas tetap hanya mengelola siswa rombelnya.
- Tabel `password_resets` (RLS). Pengaturan SMTP di `.env` (lihat `.env.example`).

## [0.2.0-alpha.6] - 2026-10-22

### Diubah
- Spin wheel: teks nama lebih besar dan menyesuaikan panjang nama dan jumlah irisan. Pada mode "tidak terpilih dua kali", nama yang sudah terpilih keluar dari roda setelah putaran selesai.
- Poll yang sudah ditutup menampilkan label "(Ditutup)".
- Word cloud: kata tidak lagi dihapus dengan mengklik kata; hapus hanya lewat tombol ✕ di pojok kata, hover hanya menyorot kata.
- Timer: tombol Jeda/Lanjutkan (sisa waktu tersimpan di server, semua layar menampilkan overlay "Dijeda").

## [0.2.0-alpha.5] - 2026-10-22

### Ditambah (tahap 16)
- Avatar (12 pilihan emoji) dan bingkai cincin (6 pilihan) yang terbuka otomatis saat total XP tercapai (GAM-05). Tampil di header dan papan peringkat; halaman Profil menampilkan pilihan terkunci beserta XP yang dibutuhkan.
- Tema XP (THM-04): Retro Game (300 XP) dan Alam Teduh (700 XP), keduanya terang dan gelap serta lulus uji kontras AA. Tema terkunci ditolak di server dan diabaikan bila XP tidak cukup.
- Guru, admin, dan sekolah yang mematikan gamifikasi: semua hadiah terbuka.
- Kolom `user_preferences.avatar` dan `frame`. Jalankan `php artisan migrate` dan `php artisan db:seed --class=PlatformSeeder` (menambah tema baru).

## [0.2.0-alpha.4] - 2026-10-22

### Ditambah (tahap 15)
- Gamifikasi (GAM-01..04, 06, 07): XP dari kartu tayang, komentar bermakna, reaksi, aktivitas live, kuis, dan kuis live; bonus juara 1-3 kuis live. XP dibatasi per hari (150) dan per jenis agar tidak bisa di-spam; setiap sumber hanya dihargai sekali.
- Level (XP level n = 60 x (n-1)^2), 10 lencana otomatis, dan streak hari sekolah (Sabtu/Minggu tidak memutus rentetan).
- Toast hadiah (XP, naik level, lencana, streak), chip level di header, kartu XP di beranda siswa, halaman `/prestasi` dan `/peringkat`.
- Peringkat kelas (mingguan dan sepanjang waktu; skor sama = peringkat sama). Guru/admin bisa menyembunyikannya per kelas; siswa hanya melihat kelasnya sendiri.
- Pengaturan sekolah: saklar gamifikasi. Semua angka bisa diatur di `config/gamify.php`.
- Tabel `xp_events`, `user_levels`, `user_badges` (RLS) dan kolom `classes.leaderboard`. Event baru `LiveQuizFinished`.

## [0.2.0-alpha.3] - 2026-10-22

### Ditambah (tahap 14)
- Aktivitas live di papan (LIV-01..04): timer hitung mundur, poll dengan grafik bergerak, word cloud, dan spin wheel pengacak nama. Satu aktivitas berjalan per papan; yang baru menggantikan yang lama. Guru memulai dari tombol "Aktivitas live" di bilah papan.
- Pemenang spin wheel diundi di server (`random_int`), roda hanya menganimasikan hasil sehingga semua layar mendarat di nama yang sama. Nama dari rombel papan atau daftar ketikan; opsi tanpa pengulangan.
- Word cloud: batas kata per siswa, kata kasar ditolak, guru bisa menghapus kata.
- Mode proyektor (BRD-20) di `/papan/{id}/proyektor`: teks besar, aktivitas memenuhi layar, kartu tampil besar, kode dan QR bergabung.
- Efek perayaan (konfeti dan suara buatan sendiri) bisa dimatikan lewat tombol "Efek" (LIV-06).
- Kanal siaran `school.{id}.board.{id}` (event `board.updated`) untuk aktivitas; cadangan pengecekan berkala bila WebSocket tidak tersambung. Kanal ini dipakai ulang untuk kartu di tahap 20.
- Tabel `board_activities` dan `activity_responses` (dengan RLS).

## [0.2.0-alpha.2] - 2026-10-22

### Diubah
- Kuis live: hitung mundur 3 detik ("Bersiap…") tampil di layar guru dan siswa; soal dan pilihan baru muncul bersamaan setelah hitungan habis (sebelumnya guru melihat soal lebih dulu).
- Layar siswa: teks pilihan jawaban diperbesar (menyesuaikan panjang teks).

### Ditambah
- Layar guru: jumlah pemilih tiap pilihan tampil live saat soal berjalan (angka dan batang di tiap warna). Hitungan hanya dikirim ke host, tidak ke siswa dan tidak lewat siaran WebSocket.
- Perintah `php artisan madingo:demo-quiz [username]` membuat kuis contoh 10 soal.

### Diperbaiki
- Reverb lewat HTTPS tidak pernah tersambung (`enabledTransports` harus `['ws','wss']`); indikator tetap "Berkala".

## [0.2.0-alpha.1] - 2026-10-22 (Fase 2, tahap 13: kuis live)

Pembaruan di laptop/server:
1. `composer update laravel/reverb -W` (paket baru: Laravel Reverb), lalu `npm install`.
2. `php artisan migrate` (kolom `quiz_sessions.live`, `quiz_attempts.team`, `quiz_answers.live_score/elapsed_ms`).
3. Isi variabel `REVERB_*` dan `BROADCAST_CONNECTION=reverb` di `.env` (contoh di `.env.example`), jalankan
   `php artisan reverb:start` (lokal) atau lewat Supervisor (server, lihat `docs/DEPLOY-aapanel.md`), lalu `npm run build`.
   Tanpa Reverb kuis live tetap berjalan dengan pengecekan berkala.

- Kuis live (QZ-10): tombol "▶ Live" di daftar kuis. Layar proyektor: lobi dengan kode, QR, dan nama pemain;
  soal dengan hitung mundur dan jumlah yang sudah menjawab; distribusi jawaban + jawaban benar; papan
  peringkat; podium beranimasi dengan konfeti.
- Layar pemain di HP: tombol jawaban besar berbentuk (bintang, bulan, petir, hati, daun, tetes), jeda baca 3 detik,
  hasil per soal ("Mantap!", skor, peringkat). Mendukung pilihan ganda, benar/salah, kompleks, isian, menjodohkan.
- Skor live = poin x ketepatan x (1 - 0,5 x waktu jawab/batas waktu); nilai 0-100 tetap dari ketepatan dan masuk
  laporan kuis, analisis butir, dan ekspor Excel yang sudah ada.
- Papan peringkat dan podium (QZ-11), mode tim 2-4 tim dibagi rata otomatis, skor tim = rata-rata anggota (QZ-12).
- Sambung ulang otomatis: muat ulang/terputus tidak kehilangan jawaban; bila WebSocket mati, layar beralih ke
  pengecekan berkala tiap 2 detik (QZ-13).
- Tamu dengan nama (AUTH-07) bila diizinkan; guru bisa mengeluarkan pemain dari lobi (tidak bisa masuk lagi).
- Real-time lewat Laravel Reverb: kanal privat per sekolah, isi siaran hanya sinyal perubahan (tanpa soal/kunci),
  pemain tidak bisa mengirim event ke pemain lain.

## [0.1.5] - 2026-10-22

Tanpa migrasi; jalankan `npm run build`.

- Perbaikan: pengguna yang pernah mengirim kartu melihat toast "Kartu terkirim!" muncul lagi setiap ada
  komentar atau hati baru dari orang lain (pesan lama terbawa pembaruan papan). Pesan flash kini punya id
  unik dan hanya ditampilkan sekali.
- Pemilik kartu mendapat notifikasi saat ada hati/bintang/suara/nilai baru dari orang lain di kartunya
  ("♥ baru di kartumu", tombol Lihat), selain notifikasi komentar. Reaksi sendiri tidak dihitung.

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
