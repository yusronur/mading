# Deploy ke aaPanel (Apache, PHP 8.2/8.3, PostgreSQL)

Panduan staging dan produksi. Contoh memakai domain `board.smpn1wajak.sch.id` dan PHP 8.3
(`/www/server/php/83/bin/php`). Sesuaikan dengan server Anda.

```bash
export PHP=/www/server/php/83/bin/php   # ketik ulang setiap membuka terminal baru
```

## 1. Situs dan PHP

1. aaPanel → Website → Add site, pilih PHP 8.3 (8.2 juga bisa).
2. Site directory → **Running directory: `/public`**. Rewrite: **laravel5**. SSL: Let's Encrypt.
3. Ekstensi PHP (App Store → PHP → Setting → Install extensions): `pdo_pgsql`, `fileinfo`, `gd`, `zip`,
   `intl`, `opcache`. Ekstensi `pgsql` (tanpa `pdo_`) tidak diperlukan.
4. Hapus `putenv` dan `proc_open` dari *disabled functions*.
5. Bila `php -m` memberi peringatan "already loaded" atau "Unable to load pgsql", hapus baris ganda
   / `extension=pgsql` di `/www/server/php/83/etc/php.ini`.

## 2. DNS

```
board   A   <IP server>
```

## 3. Kode, Composer, Node

Composer harus versi **2.2 atau lebih baru**, Node **20.19+ atau 22.12+** (Vite 7).

```bash
cd /www/wwwroot/board.smpn1wajak.sch.id
rm -f index.html .htaccess 404.html
git clone https://github.com/yusronur/mading.git .

# Composer baru (bila /usr/bin/composer masih 2.0)
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
$PHP /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer2
$PHP /usr/local/bin/composer2 install --no-dev --optimize-autoloader

# Tampilan: Node 22 dari "Node.js version manager" aaPanel (atur Command line version)
export PATH=/www/server/nodejs/v22.20.0/bin:$PATH
npm ci && npm run build
```

## 4. .env produksi

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://board.smpn1wajak.sch.id
LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_DATABASE=madingo
DB_USERNAME=madingo_app
DB_PASSWORD=<sandi acak panjang>
DB_OWNER_USERNAME=postgres
DB_OWNER_PASSWORD=<sandi postgres>   # hanya untuk install/migrate; boleh dikosongkan sesudahnya

TENANT_MODE=single
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=reverb          # lihat bagian 8; `log` bila Reverb belum dipasang
```

PostgreSQL di mesin/kontainer lain: `DB_HOST` = IP-nya, dan izinkan IP server aaPanel di
`pg_hba.conf` + `listen_addresses`.

## 5. Instalasi

```bash
$PHP artisan key:generate
$PHP artisan madingo:install --host=board.smpn1wajak.sch.id    # catat sandi admin yang tampil
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
mkdir -p storage/framework/{views,cache/data,sessions} storage/logs bootstrap/cache
chown -R www:www storage bootstrap/cache && chmod -R 775 storage bootstrap/cache
```

Lupa sandi admin:

```bash
$PHP artisan tinker
> app(\App\Core\Tenancy\TenantContext::class)->set(\App\Models\School::first());
> $u = \App\Models\User::where('username','admin')->first(); $u->password = 'SandiBaru123'; $u->must_change_password = true; $u->save();
```

## 6. Penjadwal

aaPanel → Cron → Shell script, tiap menit:

```bash
cd /www/wwwroot/board.smpn1wajak.sch.id && /www/server/php/83/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 6b. Notifikasi push (Tahap 19)

Lonceng notifikasi langsung jalan. Agar notifikasi juga muncul di layar HP/laptop (Web Push, gratis):

```bash
$PHP artisan madingo:vapid          # cetak 3 baris VAPID_*, tempel ke .env
$PHP artisan config:cache
```

Syarat: HTTPS (sudah), penjadwal tiap menit (bagian 6) untuk pengingat H-1, nilai, ringkasan guru, dan pelepasan notifikasi jam tenang.
Jangan mengganti kunci VAPID setelah dipakai: semua langganan perangkat akan terputus dan pengguna harus mengaktifkan ulang.
Setelah itu admin bisa mematikan/menyalakan push di Pengaturan, dan mengirim pesan manual lewat menu Pesan.
iPhone/iPad hanya menerima push setelah aplikasi dipasang ke layar utama (iOS 16.4+).

## 7. Pembaruan (rilis berikutnya)

```bash
cd /www/wwwroot/board.smpn1wajak.sch.id
pg_dump ... > /www/backup/madingo-$(date +%F).sql     # cadangkan dulu
$PHP artisan down
git pull
$PHP /usr/local/bin/composer2 install --no-dev --optimize-autoloader
npm ci && npm run build
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan reverb:restart      # bila memakai Reverb
$PHP artisan up
```

## 8. Real-time: Laravel Reverb (Fase 2)

Reverb adalah server WebSocket yang berjalan terus di server yang sama, hanya mendengarkan
`127.0.0.1:8080`. Peramban tersambung lewat domain sekolah (HTTPS 443) yang diteruskan Apache.
Tanpa Reverb, kuis live tetap berjalan (pengecekan berkala), hanya sedikit lebih lambat.

### 8.1 .env

```ini
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=madingo
REVERB_APP_KEY=<acak 20 karakter>
REVERB_APP_SECRET=<acak 32 karakter>
# Laravel mengirim siaran ke Reverb secara lokal
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
# Alamat yang dibuka peramban
REVERB_CLIENT_HOST=board.smpn1wajak.sch.id
REVERB_CLIENT_PORT=443
REVERB_CLIENT_SCHEME=https
REVERB_ALLOWED_ORIGINS=board.smpn1wajak.sch.id
```

Nilai acak: `$PHP artisan tinker --execute="echo Str::random(32);"`. Lalu `$PHP artisan config:cache`.

### 8.2 Proses Reverb (Supervisor)

aaPanel → App Store → **Supervisor** → Add daemon:

```
Name:      madingo-reverb
Run user:  www
Run dir:   /www/wwwroot/board.smpn1wajak.sch.id
Command:   /www/server/php/83/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
```

Port 8080 **tidak** perlu dibuka di firewall (hanya lokal).

### 8.3 Proxy WebSocket di Apache

Aktifkan modul `proxy`, `proxy_http`, `proxy_wstunnel`, `rewrite` (biasanya sudah aktif di aaPanel; cek
`httpd -M | grep proxy`). Buat berkas
`/www/server/panel/vhost/apache/extension/board.smpn1wajak.sch.id/reverb.conf`
(ikut dimuat oleh `IncludeOptional` di konfigurasi situs):

```apache
RewriteEngine On
RewriteCond %{HTTP:Upgrade} websocket [NC]
RewriteCond %{HTTP:Connection} upgrade [NC]
RewriteRule ^/app/(.*)$ ws://127.0.0.1:8080/app/$1 [P,L]
ProxyPreserveHost On
ProxyTimeout 120
```

Reload Apache. Cek: buka layar host kuis live; indikator di bilah atas harus **Langsung** (hijau).
**Berkala** (kuning) berarti peramban tidak bisa tersambung ke WebSocket: periksa Supervisor
(`madingo-reverb` RUNNING), berkas proxy di atas, dan `REVERB_CLIENT_*`.

### 8.4 Nginx (bila memakai Nginx)

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 120s;
}
```

### 8.5 Lokal (XAMPP)

```ini
BROADCAST_CONNECTION=reverb
REVERB_CLIENT_HOST=127.0.0.1
REVERB_CLIENT_PORT=8080
REVERB_CLIENT_SCHEME=http
```

Jalankan `php artisan reverb:start` di terminal terpisah selama mencoba.

### 8.6 Kartu papan realtime (Tahap 20) dan kapasitas

Sejak alpha.15 kartu, kolom, centang, nilai, dan pengaturan papan juga dikirim lewat Reverb
(kanal yang sama dengan aktivitas papan). Satu permintaan = satu sinyal per papan, dikirim
**setelah** respons. Saat WebSocket tersambung, peramban hampir tidak lagi mengecek `/sinkron`
(hanya tiap 30 detik sebagai jaring pengaman), jadi beban HTTP saat kelas ramai turun drastis.
Bila Reverb mati, peramban otomatis kembali ke pengecekan tiap 2 detik dan admin mendapat
notifikasi "Realtime (Reverb) tidak terjangkau".

Setelah `git pull`: `$PHP artisan reverb:restart` (Supervisor menyalakannya lagi).

Untuk >300 koneksi bersamaan naikkan batas berkas terbuka proses Reverb, mis. di Supervisor
tambahkan sebelum perintah: `bash -c "ulimit -n 10000 && exec /www/server/php/83/bin/php artisan reverb:start --host=127.0.0.1 --port=8080"`.
Pasang ekstensi PHP `event` (aaPanel → PHP 8.3 → Install extensions) bila tersedia; Reverb
otomatis memakainya dan jauh lebih kuat untuk ribuan koneksi.

### 8.7 Uji beban (k6)

Lihat `tests/load/README.md`. Ringkas:

```bash
$PHP artisan madingo:loadtest seed --students=200 --force   # akun uji-beban-0001…, rombel UJI-BEBAN
k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e DATA=storage/app/loadtest.json -e VUS=200 tests/load/board.js
k6 run -e BASE_URL=https://board.smpn1wajak.sch.id -e DATA=storage/app/loadtest.json -e VUS=200 tests/load/live.js
$PHP artisan madingo:loadtest clean --force                 # WAJIB setelah selesai
```

Jalankan k6 dari komputer lain (bukan server), di luar jam sekolah.
