# Deploy ke aaPanel (Apache, PHP 8.2, PostgreSQL)

Panduan ini untuk staging dan produksi. Belum dipakai di Fase 0, disiapkan agar
struktur kode sudah cocok dengan server sejak awal.

## 1. Situs dan PHP

1. aaPanel → Website → Add site: `mading.smpn1wajak.sch.id` (atau subdomain staging).
2. Root situs: folder proyek, **Running directory: `/public`**.
3. PHP 8.2. Aktifkan ekstensi: `pgsql`, `pdo_pgsql`, `fileinfo`, `gd`, `zip`, `intl`, `opcache`.
4. Hapus `exec`, `putenv`, `proc_open` dari *disabled functions* bila Laravel membutuhkannya
   (Composer dan antrean). Minimal `putenv` dan `proc_open`.
5. URL rewrite: pilih **laravel5** (atau biarkan `.htaccess` bawaan di `public/`).
6. SSL: Let's Encrypt untuk domain sekolah.

## 2. DNS domain sekolah

Di pengelola domain `smpn1wajak.sch.id`, buat rekor:

```
mading   A   <IP server>
```

## 3. Kode

```bash
cd /www/wwwroot/mading.smpn1wajak.sch.id
git clone https://github.com/yusronur/mading.git .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Hasil build tampilan: jalankan `npm ci && npm run build` di server bila Node tersedia, atau
build di laptop lalu unggah folder `public/build`.

## 4. .env produksi

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mading.smpn1wajak.sch.id

DB_DATABASE=madingo
DB_USERNAME=madingo_app
DB_PASSWORD=<sandi kuat>
DB_OWNER_USERNAME=postgres
DB_OWNER_PASSWORD=<sandi postgres server>

TENANT_MODE=single          # nanti "domain" saat go public
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database   # lalu jalankan worker (langkah 6)
```

PostgreSQL di Docker: isi `DB_HOST` dengan host/port yang dipetakan container.

## 5. Instalasi

```bash
php artisan madingo:install --host=mading.smpn1wajak.sch.id
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www:www storage bootstrap/cache
```

## 6. Antrean dan penjadwal

aaPanel → App Store → **Supervisor**, tambahkan:

```
Name: madingo-worker
Run dir: /www/wwwroot/mading.smpn1wajak.sch.id
Command: php artisan queue:work --sleep=3 --tries=1 --max-time=3600
User: www
```

aaPanel → Cron → Shell script, tiap menit:

```bash
cd /www/wwwroot/mading.smpn1wajak.sch.id && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Pembaruan (rilis berikutnya)

```bash
cd /www/wwwroot/mading.smpn1wajak.sch.id
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Cadangkan database (`pg_dump`) sebelum `migrate`. Lihat Blueprint Bab 11.3-11.4.
