# Madingo

Papan kolaborasi (alternatif Padlet) dan kuis untuk sekolah, multi-tenant.
Sekolah percontohan: **SMP Negeri 1 Wajak**. Nama di header mengikuti pengaturan sekolah
(bawaan "Mading"); nama merek Madingo hanya tampil sebagai "Didukung Madingo".

Acuan pengembangan: `docs/Blueprint_Papanku_v1.0.docx` (bab dan kode kebutuhan dirujuk di kode).

Status: **Fase 0 (Fondasi)** selesai. Lihat `CHANGELOG.md` dan `docs/JURNAL.md`.

## Teknologi

| Lapisan | Pilihan |
|---|---|
| Server | PHP 8.2+, Laravel 12 |
| Tampilan | Inertia.js 3 + Vue 3 + Tailwind CSS 4 (Vite) |
| Database | PostgreSQL 16+ dengan Row-Level Security |
| Impor Excel | PhpSpreadsheet |

Node.js hanya dipakai di laptop untuk build tampilan. Server tidak menjalankan proses Node.

---

## Instalasi di laptop (XAMPP, Windows)

### 1. Prasyarat

- XAMPP dengan PHP 8.2 atau lebih baru
- PostgreSQL (lokal, port 5432)
- [Composer](https://getcomposer.org/download/)
- [Node.js LTS](https://nodejs.org/) (20 atau lebih baru)

Aktifkan ekstensi PHP di `C:\xampp\php\php.ini` (hapus tanda `;` di depannya), lalu restart Apache:

```ini
extension=pdo_pgsql
extension=pgsql
extension=gd
extension=zip
extension=fileinfo
extension=intl
```

### 2. Pasang dependensi

```cmd
cd C:\xampp\htdocs\mading
composer install
npm install
npm run build
```

### 3. Konfigurasi

```cmd
copy .env.example .env
php artisan key:generate
```

Buka `.env`, sesuaikan bagian basis data:

```ini
DB_DATABASE=madingo
DB_USERNAME=madingo_app          # peran aplikasi, dibuat otomatis
DB_PASSWORD=isi_sandi_bebas      # sandi untuk peran aplikasi
DB_OWNER_USERNAME=postgres       # akun pemilik (superuser) lokal
DB_OWNER_PASSWORD=admin
```

Mengapa dua akun? Aplikasi berjalan dengan peran `madingo_app` yang **bukan superuser**,
sehingga Row-Level Security PostgreSQL ikut menjaga agar data satu sekolah tidak pernah
terbaca sekolah lain. Akun `postgres` hanya dipakai untuk migrasi dan instalasi.

### 4. Instal

```cmd
php artisan madingo:install --demo
```

Perintah ini membuat database dan peran aplikasi, menjalankan migrasi, mengisi tema bawaan,
sekolah, tahun ajaran aktif, dan akun `admin`. **Catat sandi admin** yang ditampilkan.
Opsi `--demo` menambahkan data contoh fiktif (2 guru, 10 siswa, 1 papan) dengan sandi `demo1234`.

Perintah ini aman diulang. Pembaruan skema berikutnya cukup dengan `php artisan migrate`
(otomatis memakai akun pemilik).

### 5. Virtual host Apache

Tambahkan ke `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName mading.test
    DocumentRoot "C:/xampp/htdocs/mading/public"
    <Directory "C:/xampp/htdocs/mading/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Tambahkan ke `C:\Windows\System32\drivers\etc\hosts` (buka Notepad sebagai Administrator):

```
127.0.0.1 mading.test
```

Pastikan `LoadModule rewrite_module modules/mod_rewrite.so` aktif di `httpd.conf`, restart
Apache, lalu buka **http://mading.test**.

Untuk mencoba dari HP di jaringan yang sama, buka `http://<IP-laptop>` setelah menambahkan
`ServerAlias` IP laptop pada virtual host di atas.

### 6. Selama pengembangan

```cmd
npm run dev
```

Vite memuat ulang tampilan otomatis saat berkas Vue diubah. Untuk hasil akhir, `npm run build`.

---

## Pengujian

```cmd
php artisan test
```

Pengujian memakai database terpisah `madingo_test` yang dibuat otomatis memakai akun pemilik
dari `.env`. Uji beban impor 900 siswa (lebih lama) dijalankan terpisah:

```cmd
php artisan test --group=slow
```

Berkas data uji fiktif untuk dicoba manual lewat halaman impor:

```cmd
php artisan madingo:sample-import students --rows=900
php artisan madingo:sample-import teachers --rows=30
```

Berkas tersimpan di `storage/app/`.

---

## Struktur kode

```
app/Core/Tenancy     Penentu sekolah aktif, global scope, job antrean per sekolah
app/Core/Database    Row-Level Security dan penyiapan peran database
app/Core/Theme       Token tema dan resolusi tema (sekolah > siswa > papan terkunci)
app/Modules/Auth     Login, ganti sandi
app/Modules/Admin    Admin console: pengaturan, tahun ajaran, rombel, pengguna, impor
app/Modules/Import   Pembaca Excel, proses impor, templat
app/Modules/Board    Papan dan kartu, kebijakan akses
app/Modules/Profile  Preferensi tampilan
resources/js/Pages   Halaman Vue per modul
docs/                Blueprint, jurnal, ADR, panduan deploy
```

Aturan wajib (Blueprint Bab 9.3) yang dijaga sejak Fase 0:

1. Setiap model bisnis memakai trait `BelongsToSchool`.
2. Setiap tabel bisnis punya `school_id` dan kebijakan RLS (`Rls::enable()` di migrasi).
3. Tidak ada warna tertulis langsung di komponen; pakai utilitas token (`bg-surface`, `text-muted`, `bg-accent`, ...).
4. Teks antarmuka berbahasa Indonesia.
5. Otorisasi lewat Policy/middleware, bukan pengecekan manual di controller.

## Deploy ke aaPanel

Lihat `docs/DEPLOY-aapanel.md`.
