<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pembantu Row-Level Security untuk migrasi (Blueprint Bab 5.5).
 *
 * Kebijakan memakai nilai sesi app.current_school yang diisi TenantContext.
 * Jika nilai kosong, tidak ada baris yang terlihat (aman secara bawaan).
 *
 * Catatan: superuser dan pemilik tabel melewati RLS. Karena itu aplikasi
 * memakai peran terpisah (madingo_app) yang bukan pemilik tabel.
 */
class Rls
{
    private const CURRENT = "NULLIF(current_setting('app.current_school', true), '')::uuid";

    /** Isolasi penuh: hanya baris milik sekolah aktif. */
    public static function enable(string $table): void
    {
        if (! self::supported()) {
            return;
        }

        $t = self::ident($table);
        $c = self::CURRENT;

        DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
        DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (school_id = {$c}) WITH CHECK (school_id = {$c})");
    }

    /**
     * Baris global (school_id NULL) boleh dibaca semua sekolah, tetapi
     * hanya baris milik sekolah aktif yang boleh ditulis. Contoh: tema bawaan.
     */
    public static function enableWithShared(string $table): void
    {
        if (! self::supported()) {
            return;
        }

        $t = self::ident($table);
        $c = self::CURRENT;

        DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
        DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (school_id IS NULL OR school_id = {$c}) WITH CHECK (school_id = {$c})");
    }

    private static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function ident(string $name): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Nama tabel tidak valid: {$name}");
        }

        return '"'.$name.'"';
    }
}
