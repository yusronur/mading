# Uji beban Madingo (k6)

Dua skenario:

| Skrip | Meniru | Ukuran utama |
|---|---|---|
| `board.js` | Satu rombel membuka satu papan; sebagian menempel kartu; semua memuat ulang kartu saat ada sinyal realtime | `board_signal_to_cards_ms` (sinyal → kartu termuat), `board_own_post_signal_ms` (kirim kartu → sinyal kembali), p95 `GET papan (partial)` |
| `live.js` | Satu guru menjalankan kuis live; semua siswa menjawab | `live_answer_ms`, p95 `GET live status`, `live_question_seen_ms` |

Keduanya memakai WebSocket (Reverb) seperti peramban asli. Tambahkan `-e WS=0` untuk meniru
kondisi tanpa Reverb (pengecekan berkala) dan membandingkan bebannya.

## 1. Siapkan data uji (di server)

```bash
php artisan madingo:loadtest seed --students=200 --password=UjiBeban123 --force
```

Membuat rombel `UJI-BEBAN`, guru `uji-beban-guru`, siswa `uji-beban-0001`…, satu papan, dan
satu kuis 10 soal. Hasilnya `storage/app/loadtest.json`; salin ke komputer yang menjalankan k6.

## 2. Jalankan (dari komputer lain)

Pasang k6: https://grafana.com/docs/k6/latest/set-up/install-k6/

```bash
# 200 siswa di satu papan selama 3 menit, tiap siswa menempel kartu rata-rata tiap 45 detik
k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e DATA=./loadtest.json -e VUS=200 -e DURATION=3m tests/load/board.js

# 200 siswa kuis live, 10 soal x 20 detik
k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e DATA=./loadtest.json -e VUS=200 tests/load/live.js
```

Variabel: `VUS`, `DURATION`, `POST_EVERY` (detik), `SECONDS`, `QUESTIONS`, `JOIN_WAIT`, `WS=0`, `DEBUG=1`.

Mulai kecil (50), naikkan bertahap (100, 200, 400) sambil memantau CPU/RAM server (aaPanel →
Monitor) dan `top` untuk proses `php-fpm`, `reverb`, `postgres`. Ambang bawaan (gagal bila
terlewati): galat HTTP < 1%, p95 muat ulang kartu < 800 ms, p95 jawaban live < 800 ms.

## 3. Bersihkan

```bash
php artisan madingo:loadtest clean --force
```

Menghapus semua akun `uji-beban-*` beserta papan, kuis, dan rombelnya.

## Catatan

- Jalankan di luar jam sekolah. Kartu uji tidak terlihat siswa asli (rombel terpisah).
- Batas laju per akun (mis. 10 kartu/menit) tetap berlaku; jangan set `POST_EVERY` < 6.
- Hasil di laptop/`php artisan serve` tidak mewakili server: ukur di server sungguhan.
