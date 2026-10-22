<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_page_shows_school_name(): void
    {
        $this->makeSchool();

        $this->get('/masuk')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('school.display_name', 'Mading')
            ->where('app.brand', config('madingo.brand')));
    }

    public function test_student_logs_in_with_nisn_and_lands_on_dashboard(): void
    {
        $school = $this->makeSchool();
        $this->makeUser($school, User::ROLE_STUDENT, ['username' => '0123456789', 'password' => 'SandiSiswa1']);

        $this->post('/masuk', ['username' => '0123456789', 'password' => 'SandiSiswa1'])
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $school = $this->makeSchool();
        $this->makeUser($school, User::ROLE_STUDENT, ['username' => '0123456789', 'password' => 'SandiSiswa1']);

        $this->post('/masuk', ['username' => '0123456789', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $school = $this->makeSchool();
        $this->makeUser($school, User::ROLE_STUDENT, ['username' => '0123456789', 'password' => 'SandiSiswa1', 'status' => 'inactive']);

        $this->post('/masuk', ['username' => '0123456789', 'password' => 'SandiSiswa1'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_lockout_after_five_failures_is_audited(): void
    {
        $school = $this->makeSchool();
        $this->makeUser($school, User::ROLE_STUDENT, ['username' => '0123456789', 'password' => 'SandiSiswa1']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/masuk', ['username' => '0123456789', 'password' => 'salah']);
        }

        // Sandi benar pun ditolak selama terkunci
        $this->post('/masuk', ['username' => '0123456789', 'password' => 'SandiSiswa1'])
            ->assertSessionHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('username'));
        $this->assertGuest();
        $this->assertSame(1, $this->inSchool($school, fn () => AuditLog::where('action', 'auth.lockout')->count()));
    }

    public function test_must_change_password_before_using_app(): void
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school, User::ROLE_STUDENT, ['username' => '0123456789', 'password' => 'abc123', 'must_change_password' => true]);

        $this->post('/masuk', ['username' => '0123456789', 'password' => 'abc123'])->assertRedirect(route('password.change'));
        $this->get('/siswa')->assertRedirect(route('password.change'));

        $this->put('/ganti-sandi', ['current_password' => 'abc123', 'password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertSessionHasErrors('password');

        $this->put('/ganti-sandi', ['current_password' => 'abc123', 'password' => 'SandiBaru2026', 'password_confirmation' => 'SandiBaru2026'])
            ->assertRedirect(route('student.dashboard'));

        $this->assertFalse($this->inSchool($school, fn () => $user->fresh()->must_change_password));
        $this->get('/siswa')->assertOk();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school, User::ROLE_TEACHER);

        $this->actingAs($user)->get('/guru')->assertOk();
        $fresh = $this->inSchool($school, function () use ($user) {
            $user->update(['status' => 'inactive']);

            return $user->fresh();
        });
        $this->actingAs($fresh)->get('/guru')->assertRedirect(route('login'));
    }
}
