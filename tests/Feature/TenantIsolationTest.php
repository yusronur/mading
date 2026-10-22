<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** TC-01 dan Blueprint Bab 5.1: data satu sekolah tidak pernah terlihat sekolah lain. */
class TenantIsolationTest extends TestCase
{
    public function test_app_connection_is_not_superuser_and_cannot_bypass_rls(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertFalse((bool) $role->rolsuper, 'Koneksi aplikasi tidak boleh superuser.');
        $this->assertFalse((bool) $role->rolbypassrls, 'Koneksi aplikasi tidak boleh BYPASSRLS.');
    }

    public function test_eloquent_scope_isolates_schools(): void
    {
        [$a, $b] = [$this->makeSchool('sekolah-a'), $this->makeSchool('sekolah-b')];
        $teacherA = $this->makeUser($a, User::ROLE_TEACHER);
        $this->inSchool($a, fn () => Board::create(['owner_id' => $teacherA->id, 'title' => 'Papan A', 'visibility' => 'school']));

        $this->assertSame(1, $this->inSchool($a, fn () => Board::count()));
        $this->assertSame(0, $this->inSchool($b, fn () => Board::count()));
        $this->assertSame(0, Board::count(), 'Tanpa sekolah aktif tidak ada data yang terlihat.');
    }

    public function test_rls_blocks_raw_queries_without_eloquent(): void
    {
        [$a, $b] = [$this->makeSchool('sekolah-a'), $this->makeSchool('sekolah-b')];
        $this->makeUser($a, User::ROLE_STUDENT);
        $this->makeUser($a, User::ROLE_STUDENT);

        // Query mentah tanpa global scope tetap dibatasi PostgreSQL
        $this->assertSame(0, $this->inSchool($b, fn () => DB::table('users')->where('role', 'student')->count()));
        $this->assertSame(2, $this->inSchool($a, fn () => DB::table('users')->where('role', 'student')->count()));
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_rls_rejects_writes_into_another_school(): void
    {
        [$a, $b] = [$this->makeSchool('sekolah-a'), $this->makeSchool('sekolah-b')];

        $this->expectException(QueryException::class);

        $this->inSchool($b, fn () => DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'school_id' => $a->id,
            'username' => 'penyusup',
            'name' => 'Penyusup',
            'password' => 'x',
            'role' => 'teacher',
            'profile' => '{}',
        ]));
    }

    public function test_model_refuses_creating_for_other_school(): void
    {
        [$a, $b] = [$this->makeSchool('sekolah-a'), $this->makeSchool('sekolah-b')];

        $this->expectException(\LogicException::class);
        $this->inSchool($b, fn () => User::create([
            'school_id' => $a->id, 'username' => 'x', 'name' => 'X', 'password' => 'RahasiaUji123', 'role' => 'teacher',
        ]));
    }

    public function test_domain_mode_resolves_school_and_hides_other_school_board(): void
    {
        config(['madingo.tenant.mode' => 'domain', 'madingo.tenant.base_domain' => 'madingo.test']);

        $a = $this->makeSchool('sekolah-a', 'mading.sekolah-a.sch.id');
        $b = $this->makeSchool('sekolah-b');
        $teacherA = $this->makeUser($a, User::ROLE_TEACHER);
        $teacherB = $this->makeUser($b, User::ROLE_TEACHER);
        $boardB = $this->inSchool($b, fn () => Board::create(['owner_id' => $teacherB->id, 'title' => 'Rahasia B', 'visibility' => 'school']));

        // Domain kustom sekolah A
        $this->get('http://mading.sekolah-a.sch.id/masuk')->assertOk()
            ->assertInertia(fn ($page) => $page->where('school.name', 'Sekolah sekolah-a'));
        // Subdomain platform sekolah B
        $this->get('http://sekolah-b.madingo.test/masuk')->assertOk()
            ->assertInertia(fn ($page) => $page->where('school.name', 'Sekolah sekolah-b'));
        // Domain tak dikenal
        $this->get('http://tidak-ada.madingo.test/masuk')->assertNotFound();

        // Guru A membuka URL papan B lewat domain A: 404, bukan 403
        $this->actingAs($teacherA)->get('http://mading.sekolah-a.sch.id/papan/'.$boardB->id)->assertNotFound();
    }

    public function test_user_cannot_log_in_on_another_schools_domain(): void
    {
        config(['madingo.tenant.mode' => 'domain', 'madingo.tenant.base_domain' => 'madingo.test']);
        $a = $this->makeSchool('sekolah-a');
        $this->makeSchool('sekolah-b');
        $this->makeUser($a, User::ROLE_TEACHER, ['username' => 'guru.a', 'password' => 'RahasiaUji123']);

        $this->post('http://sekolah-b.madingo.test/masuk', ['username' => 'guru.a', 'password' => 'RahasiaUji123'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post('http://sekolah-a.madingo.test/masuk', ['username' => 'guru.a', 'password' => 'RahasiaUji123'])
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_suspended_school_is_not_reachable(): void
    {
        $school = $this->makeSchool('sekolah-uji');
        $school->update(['status' => 'suspended']);

        $this->get('/masuk')->assertNotFound();
    }
}
