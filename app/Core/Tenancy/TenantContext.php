<?php

namespace App\Core\Tenancy;

use App\Models\School;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya sumber kebenaran "sekolah aktif" pada sebuah permintaan,
 * job antrean, atau perintah konsol.
 *
 * Saat sekolah di-set, nilai juga dikirim ke sesi PostgreSQL
 * (app.current_school) agar kebijakan Row-Level Security ikut membatasi.
 */
class TenantContext
{
    private ?School $school = null;

    private bool $bypass = false;

    public function set(?School $school): void
    {
        $this->school = $school;
        $this->applyToConnection();
    }

    public function forget(): void
    {
        $this->set(null);
    }

    public function school(): ?School
    {
        return $this->school;
    }

    public function id(): ?string
    {
        return $this->school?->getKey();
    }

    public function check(): bool
    {
        return $this->school !== null;
    }

    /**
     * Jalankan callback dalam konteks sekolah tertentu lalu kembalikan
     * konteks sebelumnya.
     */
    public function run(School $school, callable $callback): mixed
    {
        $previous = $this->school;
        $this->set($school);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /**
     * Matikan global scope sekolah (khusus fitur platform/super-admin).
     * RLS di database tetap berlaku untuk koneksi aplikasi.
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    public function scopeBypassed(): bool
    {
        return $this->bypass;
    }

    /**
     * Kirim sekolah aktif ke sesi PostgreSQL untuk RLS.
     */
    public function applyToConnection(?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select(
            "select set_config('app.current_school', ?, false)",
            [$this->id() ?? '']
        );
    }
}
