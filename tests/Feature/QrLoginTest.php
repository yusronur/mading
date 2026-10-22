<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tahap 10: login QR, wali kelas, remember me, log audit. */
class QrLoginTest extends TestCase
{
    private object $school;

    private User $admin;

    private User $wali;

    private User $ani;

    private User $budi;

    private SchoolClass $a;

    private SchoolClass $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->admin = $this->inSchool($this->school, fn () => User::where('username', 'admin')->first());
        $this->wali = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Bu Wali']);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani', 'must_change_password' => true]);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        [$this->a, $this->b] = $this->inSchool($this->school, function () {
            $year = AcademicYear::active()->id;
            $a = SchoolClass::create(['academic_year_id' => $year, 'name' => '7A', 'grade' => 7, 'homeroom_teacher_id' => $this->wali->id]);
            $b = SchoolClass::create(['academic_year_id' => $year, 'name' => '7B', 'grade' => 7]);
            foreach ([[$a, $this->ani], [$b, $this->budi]] as [$c, $u]) {
                DB::table('class_members')->insert(['class_id' => $c->id, 'user_id' => $u->id, 'school_id' => $u->school_id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return [$a, $b];
        });
    }

    private function qrUrl(User $u): string
    {
        $this->actingAs($this->admin)->get(route('credentials.print', ['user_id' => $u->id]))
            ->assertInertia(function ($p) use (&$url) {
                $url = $p->toArray()['props']['cards'][0]['qr_url'];
            });

        return $url;
    }

    public function test_qr_login_signs_in_with_remember_and_skips_forced_password_change(): void
    {
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['class_id' => $this->a->id, 'action' => 'generate'])->assertSessionHas('success');
        $url = $this->qrUrl($this->ani);
        $this->assertStringContainsString('/masuk/qr/', $url);

        // Token tersimpan sebagai hash; teks aslinya hanya terenkripsi
        $raw = $this->inSchool($this->school, fn () => DB::table('users')->where('id', $this->ani->id)->first());
        $this->assertStringNotContainsString(basename($url), $raw->qr_token);
        $this->assertSame(hash('sha256', basename($url)), $raw->qr_token_hash);

        auth()->logout();
        $this->flushSession();
        $res = $this->get($url);
        $res->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($this->inSchool($this->school, fn () => $this->ani->fresh()));
        $this->assertNotEmpty(collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web')));

        // Sandi awal tidak dipaksa diganti saat masuk lewat QR
        $this->get(route('student.dashboard'))->assertOk();
        $this->assertTrue($this->inSchool($this->school, fn () => AuditLog::where('action', 'auth.qr_login')->exists()));
    }

    public function test_regenerate_and_revoke_invalidate_old_card(): void
    {
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['user_id' => $this->ani->id, 'class_id' => $this->a->id, 'action' => 'generate']);
        $old = $this->qrUrl($this->ani);
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['user_id' => $this->ani->id, 'class_id' => $this->a->id, 'action' => 'regenerate']);
        $new = $this->qrUrl($this->ani);
        $this->assertNotSame($old, $new);

        auth()->logout();
        $this->get($old)->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->admin)->post(route('credentials.qr'), ['class_id' => $this->a->id, 'action' => 'revoke'])->assertSessionHas('success');
        auth()->logout();
        $this->get($new)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_inactive_student_cannot_use_qr_and_bad_token_is_404(): void
    {
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['class_id' => $this->a->id, 'action' => 'generate']);
        $url = $this->qrUrl($this->ani);
        $this->inSchool($this->school, fn () => $this->ani->update(['status' => 'inactive']));
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $this->get('/masuk/qr/pendek')->assertNotFound();
    }

    public function test_homeroom_teacher_manages_only_own_class(): void
    {
        $this->actingAs($this->wali)->get(route('credentials.index'))
            ->assertInertia(fn ($p) => $p->where('classId', $this->a->id)->where('canAll', false)->has('classes', 1)->has('students', 1));
        $this->actingAs($this->wali)->get(route('credentials.index', ['class_id' => $this->b->id]))->assertForbidden();
        $this->actingAs($this->wali)->get(route('credentials.print', ['all' => 1]))->assertForbidden();

        // Reset sandi siswa di kelas sendiri (AUTH-04), tidak bisa di kelas lain
        $this->actingAs($this->wali)->post(route('credentials.reset'), ['class_id' => $this->a->id, 'user_id' => $this->ani->id])->assertSessionHas('success');
        $this->actingAs($this->wali)->post(route('credentials.reset'), ['class_id' => $this->a->id, 'user_id' => $this->budi->id])->assertNotFound();
        $this->actingAs($this->wali)->post(route('credentials.reset'), ['class_id' => $this->b->id, 'user_id' => $this->budi->id])->assertForbidden();
        $this->actingAs($this->wali)->post(route('credentials.qr'), ['class_id' => $this->b->id, 'action' => 'generate'])->assertForbidden();

        // Guru bukan wali kelas tidak punya menu ini
        $other = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->actingAs($other)->get(route('credentials.index'))->assertForbidden();
        $this->actingAs($this->wali)->get(route('teacher.dashboard'))->assertInertia(fn ($p) => $p->where('auth.user.is_homeroom', true));
    }

    public function test_audit_log_page_is_admin_only_and_filterable(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.reset-password', $this->budi->id));
        $this->actingAs($this->admin)->post(route('credentials.qr'), ['class_id' => $this->a->id, 'action' => 'generate']);

        $this->actingAs($this->wali)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['group' => 'user']))
            ->assertInertia(fn ($p) => $p->component('Admin/AuditLog')->has('logs.data', 1)->where('logs.data.0.target', 'Budi')->where('logs.data.0.label', 'Reset sandi'));
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => 'Budi']))
            ->assertInertia(fn ($p) => $p->where('logs.data.0.action', 'user.password_reset'));
    }
}
