<?php

namespace App\Core\Tenancy;

use App\Models\School;
use App\Models\SchoolDomain;
use Illuminate\Http\Request;

/**
 * Menentukan sekolah dari permintaan (ADR-004).
 *
 * Mode "single" : sekolah bawaan dari konfigurasi (tahap sekolah sendiri).
 * Mode "domain" : 1) cocokkan host di tabel school_domains (domain kustom,
 *                    mis. mading.smpn1wajak.sch.id)
 *                 2) subdomain dari base domain (mis. smpn1wajak.madingo.id)
 *
 * Tabel schools dan school_domains tidak memakai RLS karena harus dibaca
 * sebelum sekolah diketahui.
 */
class TenantResolver
{
    public function resolve(Request $request): ?School
    {
        $mode = config('madingo.tenant.mode', 'single');

        $school = $mode === 'domain'
            ? $this->fromHost(strtolower($request->getHost()))
            : School::where('slug', config('madingo.tenant.default_slug'))->first();

        if ($school === null || $school->status === 'suspended') {
            return null;
        }

        return $school;
    }

    public function fromHost(string $host): ?School
    {
        $domain = SchoolDomain::where('host', $host)->first();

        if ($domain !== null) {
            return $domain->school;
        }

        $base = strtolower((string) config('madingo.tenant.base_domain'));

        if ($base !== '' && str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));

            if ($slug !== '' && ! str_contains($slug, '.')) {
                return School::where('slug', $slug)->first();
            }
        }

        return null;
    }
}
