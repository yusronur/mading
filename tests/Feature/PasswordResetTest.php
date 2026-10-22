<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Reset sandi lewat email, edit pengguna oleh admin, dan kartu akun guru. */
class PasswordResetTest extends TestCase
{
    private object $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->admin = $this->inSchool($this->school, fn () => User::where('username', 'admin')->first());
    }

    private function tokenFrom(ResetPasswordMail $mail): string
    {
        preg_match('#/atur-sandi/([A-Za-z0-9]{48})#', $mail->url, $m);

        return $m[1];
    }

    public function test_full_reset_flow_by_email(): void
    {
        Mail::fake();
        $u = $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => '0123', 'email' => 'siswa@example.com', 'password' => 'SandiLama1']);

        $this->post(route('password.send'), ['username' => '0123'])->assertSessionHas('success');
        Mail::assertSent(ResetPasswordMail::class, fn ($m) => $m->hasTo('siswa@example.com'));
        $mail = Mail::sent(ResetPasswordMail::class)->first();
        $token = $this->tokenFrom($mail);

        // Hanya hash yang tersimpan
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('password_resets')->where('token_hash', $token)->count()));

        $this->get(route('password.reset', $token))->assertInertia(fn ($p) => $p->component('Auth/ResetPassword')->where('valid', true));
        $this->post(route('password.reset.update', $token), ['password' => 'lemah', 'password_confirmation' => 'lemah'])->assertSessionHasErrors('password');
        $this->post(route('password.reset.update', $token), ['password' => 'SandiBaru123', 'password_confirmation' => 'SandiBaru123'])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('SandiBaru123', $this->inSchool($this->school, fn () => $u->fresh()->password)));

        // Sekali pakai
        $this->get(route('password.reset', $token))->assertInertia(fn ($p) => $p->where('valid', false));
        $this->post(route('password.reset.update', $token), ['password' => 'SandiLain123', 'password_confirmation' => 'SandiLain123'])->assertRedirect(route('password.forgot'));
        $this->post('/masuk', ['username' => '0123', 'password' => 'SandiBaru123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_no_account_enumeration_and_rules(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => 'tanpa-email']);
        $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => 'nonaktif', 'email' => 'x@example.com', 'status' => 'inactive']);
        $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => 'ada', 'email' => 'a@example.com']);

        $msg = fn (string $u) => $this->post(route('password.send'), ['username' => $u])->assertSessionHas('success')->getSession()->get('success');
        $same = $msg('tidak-ada');
        $this->assertSame($same, $msg('tanpa-email'));
        $this->assertSame($same, $msg('nonaktif'));
        $this->assertSame($same, $msg('ada'));
        Mail::assertSent(ResetPasswordMail::class, 1); // hanya akun aktif ber-email

        // Batas 3 permintaan per jam per akun
        $msg('ada'); $msg('ada'); $msg('ada'); $msg('ada');
        Mail::assertSent(ResetPasswordMail::class, 3);
    }

    public function test_expired_token_and_session_logout(): void
    {
        Mail::fake();
        $u = $this->makeUser($this->school, User::ROLE_TEACHER, ['username' => 'guru1', 'email' => 'g@example.com']);
        $this->post(route('password.send'), ['username' => 'guru1']);
        $token = $this->tokenFrom(Mail::sent(ResetPasswordMail::class)->first());

        $this->travel(61)->minutes();
        $this->post(route('password.reset.update', $token), ['password' => 'SandiBaru123', 'password_confirmation' => 'SandiBaru123'])->assertRedirect(route('password.forgot'));
        $this->travelBack();
        $this->assertFalse(Hash::check('SandiBaru123', $this->inSchool($this->school, fn () => $u->fresh()->password)));

        $this->get(route('password.reset', 'bukan-token'))->assertNotFound();
    }

    public function test_user_sets_own_email_with_password_confirmation(): void
    {
        $u = $this->makeUser($this->school, User::ROLE_STUDENT, ['password' => 'SandiSaya123']);

        $this->actingAs($u)->put(route('profile.email'), ['email' => 'saya@example.com', 'current_password' => 'salah'])->assertSessionHasErrors('current_password');
        $this->actingAs($u)->put(route('profile.email'), ['email' => 'bukan-email', 'current_password' => 'SandiSaya123'])->assertSessionHasErrors('email');
        $this->actingAs($u)->put(route('profile.email'), ['email' => 'Saya@Example.com', 'current_password' => 'SandiSaya123'])->assertSessionHasNoErrors();
        $this->assertSame('saya@example.com', $this->inSchool($this->school, fn () => $u->fresh()->email));
    }

    public function test_admin_edits_identity_and_moves_class(): void
    {
        [$a, $b] = $this->inSchool($this->school, fn () => [
            SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]),
            SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7B', 'grade' => 7]),
        ]);
        $s = $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => '111', 'name' => 'Lama']);
        $other = $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => '222']);
        $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $a->id, 'user_id' => $s->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));

        $payload = ['username' => '111', 'name' => 'Nama <b>Baru</b>', 'email' => 'Baru@Example.com', 'gender' => 'P', 'birth_date' => '2012-05-01', 'class_id' => $b->id];
        $this->actingAs($this->admin)->put(route('admin.users.update', $s), $payload)->assertSessionHasNoErrors();

        $fresh = $this->inSchool($this->school, fn () => $s->fresh());
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame('baru@example.com', $fresh->email);
        $this->assertSame('2012-05-01', $fresh->birth_date->toDateString());
        $this->assertSame([$b->id], $this->inSchool($this->school, fn () => DB::table('class_members')->where('user_id', $s->id)->pluck('class_id')->all()));

        $this->actingAs($this->admin)->put(route('admin.users.update', $s), ['username' => '222'] + $payload)->assertSessionHasErrors('username');
        $this->actingAs($this->admin)->put(route('admin.users.update', $s), ['email' => 'salah'] + $payload)->assertSessionHasErrors('email');

        // Bukan admin: ditolak
        $this->actingAs($other)->put(route('admin.users.update', $s), $payload)->assertForbidden();
    }

    public function test_admin_can_print_teacher_cards_in_bulk_but_not_teachers(): void
    {
        $t1 = $this->makeUser($this->school, User::ROLE_TEACHER, ['username' => 'g1', 'name' => 'Guru Satu', 'must_change_password' => true]);
        $t2 = $this->makeUser($this->school, User::ROLE_TEACHER, ['username' => 'g2', 'name' => 'Guru Dua', 'must_change_password' => true]);
        $this->makeUser($this->school, User::ROLE_STUDENT, ['username' => 's1', 'must_change_password' => true]);

        $this->actingAs($this->admin)->post(route('credentials.generate'), ['role' => 'teacher'])->assertSessionHas('success');
        $this->assertSame(2, $this->inSchool($this->school, fn () => User::where('role', 'teacher')->whereNotNull('initial_password')->count()));
        $this->assertSame(0, $this->inSchool($this->school, fn () => User::where('role', 'student')->whereNotNull('initial_password')->count())); // siswa tidak tersentuh

        $this->actingAs($this->admin)->get(route('credentials.index', ['role' => 'teacher']))->assertInertia(fn ($p) => $p
            ->where('role', 'teacher')->has('students', 2)->where('stats.total', 2));

        $this->actingAs($this->admin)->get(route('credentials.print', ['role' => 'teacher', 'all' => 1]))->assertInertia(fn ($p) => $p
            ->where('title', 'Guru')->has('cards', 2)->where('cards.0.role_label', 'Guru')->where('cards.0.qr_url', null)->whereType('cards.0.password', 'string'));

        // QR hanya untuk siswa
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['role' => 'teacher', 'action' => 'generate'])->assertStatus(422);

        // Wali kelas (guru biasa) tidak boleh memilih peran guru
        $plain = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->actingAs($plain)->get(route('credentials.index', ['role' => 'teacher']))->assertForbidden();
        $this->actingAs($plain)->get(route('credentials.print', ['role' => 'teacher', 'all' => 1]))->assertForbidden();
    }

    public function test_login_page_offers_reset_only_when_mail_is_configured(): void
    {
        $this->get('/masuk')->assertInertia(fn ($p) => $p->where('reset_enabled', false));
        config(['mail.default' => 'smtp']);
        $this->get('/masuk')->assertInertia(fn ($p) => $p->where('reset_enabled', true));
    }
}
