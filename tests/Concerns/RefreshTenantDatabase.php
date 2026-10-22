<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Migrasi sekali per proses pengujian (dengan koneksi pemilik), lalu
 * kosongkan tabel sebelum setiap tes. Tidak memakai transaksi agar
 * koneksi pemilik dan koneksi aplikasi (yang tunduk RLS) melihat data
 * yang sama.
 */
trait RefreshTenantDatabase
{
    private static bool $migrated = false;

    protected function refreshTenantDatabase(): void
    {
        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$migrated = true;
        }

        $owner = DB::connection('pgsql_owner');
        $tables = collect($owner->select("select tablename from pg_tables where schemaname = 'public'"))
            ->pluck('tablename')
            ->reject(fn ($t) => $t === 'migrations')
            ->map(fn ($t) => '"'.$t.'"')
            ->implode(', ');

        if ($tables !== '') {
            $owner->statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");
        }

        $this->artisan('db:seed', ['--database' => 'pgsql_owner', '--force' => true]);
    }
}
