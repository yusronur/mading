# Jurnal Pengembangan

Satu entri per sesi kerja, maksimal lima baris (Blueprint Bab 17.1).

## 2026-10-21 (Rabu) - Fase 0
Fase: F0 / tahap 1-4
Dikerjakan: kerangka Laravel+Inertia+Vue, tenancy + RLS + peran DB ganda, login, admin console, impor guru/siswa, token tema, irisan tipis papan, pengujian otomatis.
Keputusan: Laravel 12 (server PHP 8.2), Inertia 3, peran `madingo_app` non-superuser (ADR-011), XAMPP -> aaPanel (ADR-012).
Hasil: 40 tes lulus (337 asersi); impor 900 siswa 17,3 detik dengan setelan hash produksi.
Catatan: sandi awal impor di-hash bcrypt cost 8 agar 900 akun cepat; di-hash ulang standar saat login/ganti sandi.
Berikutnya: uji coba di XAMPP dengan data asli, deploy staging aaPanel, lalu Fase 1 tahap 5 (format papan Grid/Stream/Shelf).

## 2026-10-21 (Rabu) - Revisi uji coba 0.0.2
Fase: F0 / perbaikan
Dikerjakan: pengaturan papan lengkap, sinkron papan 2 dtk, salin kode + toast, tanpa dialog bawaan peramban, kartu akun + sandi awal tersimpan terenkripsi.
Keputusan: ADR-013 (tanpa dialog bawaan), ADR-014 (pemeriksaan versi sebelum WebSocket), ADR-015 (sandi awal terenkripsi).
Hasil: 54 tes lulus; uji peramban: kartu siswa tampil di layar guru 2,2 dtk; dialog sandi tetap terbuka.
Berikutnya: Fase 1 tahap 5 (format papan Grid/Stream/Shelf) setelah uji coba di laptop.

## 2026-10-22 (Kamis) - Fase 1 selesai (0.1.0)
Fase: F1 / tahap 5-12
Dikerjakan: format papan, isi kartu, komentar/reaksi, moderasi, dasbor guru + bagikan/ekspor, login QR + wali kelas + log, bank soal + impor Excel/Word, kuis mandiri + laporan.
Keputusan: ADR-016 (snapshot kuis, penilaian di server); zona waktu sesi database dikunci (`DB_TIMEZONE`).
Hasil: 109 tes lulus; alur guru-siswa diuji di peramban (desktop dan HP 390 px).
Berikutnya: uji coba ke 3-5 guru percontohan, deploy staging aaPanel, lalu Fase 2 (real-time, kuis live, gamifikasi).


## 2026-10-23 (Jumat) - Revisi uji coba 0.1.1
Fase: F1 / perbaikan
Dikerjakan: 10 revisi (notifikasi dan animasi kartu, kuis via kode, navigasi kembali, urutan soal, soal menjodohkan, pantauan langsung, bank publik + pencarian, datepicker sendiri, modal tetap, cetak bank soal).
Keputusan: ADR-017.
Hasil: 119 tes lulus; semua fitur diuji di peramban (desktop dan HP), termasuk seret-lepas dan ketuk-pilih menjodohkan.
Berikutnya: uji coba ke guru percontohan, lalu Fase 2.

## 2026-10-22 (Kamis) - Tamu kuis dan durasi wajib (0.1.3)
Fase: F1 / perbaikan
Dikerjakan: perbaikan error laporan kuis (0.1.2), kuis publik dengan kode, jalur tamu kode + nama, durasi pengerjaan wajib per percobaan.
Keputusan: ADR-018.
Hasil: 123 tes lulus; alur tamu diuji di peramban HP (kunci halaman, timer 15 menit sesuai durasi).
