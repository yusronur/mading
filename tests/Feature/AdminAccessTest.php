<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_roles_reach_only_their_areas(): void
    {
        $school = $this->makeSchool();
        $admin = $this->inSchool($school, fn () => User::where('username', 'admin')->first());
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);

        $this->get('/admin')->assertRedirect(route('login'));

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($teacher)->get('/admin')->assertForbidden();
        $this->actingAs($student)->get('/admin/pengguna')->assertForbidden();
        $this->actingAs($student)->get('/guru')->assertForbidden();
        $this->actingAs($teacher)->get('/siswa')->assertForbidden();
    }

    public function test_home_redirects_by_role(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);

        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($teacher)->get('/')->assertRedirect(route('teacher.dashboard'));
        $this->actingAs($student)->get('/')->assertRedirect(route('student.dashboard'));
    }

    public function test_admin_pages_render(): void
    {
        $school = $this->makeSchool();
        $admin = $this->inSchool($school, fn () => User::where('username', 'admin')->first());
        $this->actingAs($admin);

        foreach (['/admin', '/admin/pengaturan', '/admin/tahun-ajaran', '/admin/rombel', '/admin/pengguna', '/admin/impor', '/profil', '/guru'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_can_reset_password_and_deactivate_user(): void
    {
        $school = $this->makeSchool();
        $admin = $this->inSchool($school, fn () => User::where('username', 'admin')->first());
        $student = $this->makeUser($school, User::ROLE_STUDENT);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $student->id))
            ->assertSessionHas('credential_for', $student->id);
        $this->assertTrue($this->inSchool($school, fn () => $student->fresh()->must_change_password));

        $this->actingAs($admin)->put(route('admin.users.status', $student->id), ['status' => 'inactive'])
            ->assertSessionHas('success');
        $this->assertSame('inactive', $this->inSchool($school, fn () => $student->fresh()->status));
    }

    public function test_invalid_uuid_in_url_is_404_not_500(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);

        $this->actingAs($teacher)->get('/papan/bukan-uuid')->assertNotFound();
    }
}
