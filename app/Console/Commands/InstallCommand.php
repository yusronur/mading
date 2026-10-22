<?php

namespace App\Console\Commands;

use App\Core\Database\Provisioner;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Instalasi sekali jalan (juga aman diulang):
 *  1. buat database dan peran aplikasi non-superuser (RLS aktif)
 *  2. jalankan migrasi dengan akun pemilik + beri hak akses
 *  3. isi tema bawaan, sekolah, tahun ajaran aktif, akun admin
 *  4. (opsional) data contoh
 */
class InstallCommand extends Command
{
    protected $signature = 'madingo:install
        {--admin-password= : Sandi admin awal (bawaan: acak, wajib diganti saat login)}
        {--host= : Domain kustom sekolah, mis. mading.smpn1wajak.sch.id}
        {--demo : Tambahkan data contoh fiktif (guru, siswa, papan)}
        {--skip-db : Lewati pembuatan database dan peran}';

    protected $description = 'Menyiapkan database, peran aplikasi, migrasi, dan sekolah bawaan Madingo';

    public function handle(SchoolSeeder $schoolSeeder, DemoSeeder $demoSeeder): int
    {
        $owner = config('database.connections.pgsql_owner');
        $app = config('database.connections.pgsql');

        if (($app['driver'] ?? null) !== 'pgsql') {
            $this->error('Madingo membutuhkan PostgreSQL (DB_CONNECTION=pgsql).');

            return self::FAILURE;
        }

        if ($app['username'] === $owner['username']) {
            $this->error('DB_USERNAME tidak boleh sama dengan DB_OWNER_USERNAME. Aplikasi harus memakai peran terpisah agar Row-Level Security berlaku.');

            return self::FAILURE;
        }

        if (! $this->option('skip-db')) {
            $this->components->task('Menyiapkan database dan peran aplikasi', function () use ($owner, $app) {
                $p = new Provisioner($owner['host'], (string) $owner['port'], $owner['username'], (string) $owner['password']);
                $p->ensureDatabase($owner['database']);
                $p->ensureAppRole($app['username'], (string) $app['password'], $owner['database']);
            });
        }

        DB::purge('pgsql');
        DB::purge('pgsql_owner');

        $this->components->info('Menjalankan migrasi');
        $this->call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
        $this->call('db:seed', ['--database' => 'pgsql_owner', '--force' => true]);

        // Sekolah bawaan dibuat lewat koneksi pemilik, lalu kembali ke koneksi aplikasi
        $default = config('database.default');
        config(['database.default' => 'pgsql_owner']);

        try {
            $result = $schoolSeeder->run(
                config('madingo.tenant.default_slug'),
                config('madingo.tenant.default_name'),
                config('madingo.tenant.default_npsn'),
                $this->option('admin-password') ?: null,
                $this->option('host') ?: null,
            );

            $demo = $this->option('demo') ? $demoSeeder->run($result['school']) : null;
        } finally {
            config(['database.default' => $default]);
        }

        $this->newLine();
        $this->components->info('Madingo siap dipakai.');
        $this->components->twoColumnDetail('Sekolah', $result['school']->name.' ('.$result['school']->slug.')');
        $this->components->twoColumnDetail('Login admin', 'admin');

        if ($result['admin_password']) {
            $this->components->twoColumnDetail('Sandi admin', $result['admin_password']);
            $this->components->warn('Catat sandi admin di atas. Sandi ini hanya ditampilkan sekali.');
        } else {
            $this->components->twoColumnDetail('Sandi admin', '(tidak berubah, akun admin sudah ada)');
        }

        if ($demo) {
            $this->components->twoColumnDetail('Akun guru demo', implode(', ', $demo['teachers']));
            $this->components->twoColumnDetail('Akun siswa demo', $demo['students'][0].' s.d. '.end($demo['students']));
            $this->components->twoColumnDetail('Sandi akun demo', DemoSeeder::PASSWORD);
            $this->components->twoColumnDetail('Kode papan demo', $demo['board_code']);
        }

        return self::SUCCESS;
    }
}
