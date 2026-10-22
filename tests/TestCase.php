<?php

namespace Tests;

use App\Core\Tenancy\TenantContext;
use App\Models\School;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->refreshTenantDatabase();
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Buat sekolah lengkap (pengaturan, tahun ajaran aktif, admin). */
    protected function makeSchool(string $slug = 'sekolah-uji', ?string $host = null): School
    {
        return app(SchoolSeeder::class)->run($slug, 'Sekolah '.$slug, null, 'AdminRahasia123', $host)['school'];
    }

    /** Jalankan kode dalam konteks sekolah tertentu (RLS + global scope). */
    protected function inSchool(School $school, callable $callback): mixed
    {
        return $this->tenant()->run($school, $callback);
    }

    protected function makeUser(School $school, string $role, array $attributes = []): User
    {
        return $this->inSchool($school, fn () => User::create($attributes + [
            'username' => $role.'-'.substr(md5(uniqid('', true)), 0, 8),
            'name' => ucfirst($role).' Uji',
            'password' => 'RahasiaUji123',
            'role' => $role,
            'status' => 'active',
            'must_change_password' => false,
        ]));
    }
}
