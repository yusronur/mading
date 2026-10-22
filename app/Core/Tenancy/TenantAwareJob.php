<?php

namespace App\Core\Tenancy;

use App\Models\School;

/**
 * Untuk job antrean: simpan school_id saat job dibuat, lalu pulihkan
 * konteks sekolah (dan RLS) saat job dijalankan pekerja antrean.
 *
 * Pemakaian: use TenantAwareJob; panggil $this->rememberTenant() di
 * konstruktor, lalu bungkus isi handle() dengan $this->inTenant(fn () => ...).
 */
trait TenantAwareJob
{
    public ?string $tenantSchoolId = null;

    protected function rememberTenant(): void
    {
        $this->tenantSchoolId = app(TenantContext::class)->id();
    }

    protected function inTenant(callable $callback): mixed
    {
        $school = School::findOrFail($this->tenantSchoolId);

        return app(TenantContext::class)->run($school, $callback);
    }
}
