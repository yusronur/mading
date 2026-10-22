<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Revisi Fase 1: admin menambah guru/siswa manual tanpa impor. */
class AdminUserCreateTest extends TestCase
{
    public function test_admin_creates_student_and_teacher_manually(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeUser($school, User::ROLE_ADMIN);
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $class = $this->inSchool($school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]));

        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'student', 'username' => '0123456789', 'name' => ' Siti <b>Aminah</b> ', 'gender' => 'P', 'class_id' => $class->id])
            ->assertSessionHasNoErrors()->assertSessionHas('credential_for');

        $s = $this->inSchool($school, fn () => User::where('username', '0123456789')->first());
        $this->assertSame(User::ROLE_STUDENT, $s->role);
        $this->assertSame('Siti Aminah', $s->name);
        $this->assertTrue($s->must_change_password);
        $this->assertSame(6, strlen($s->initial_password));
        $this->assertTrue($this->inSchool($school, fn () => DB::table('class_members')->where('user_id', $s->id)->where('class_id', $class->id)->exists()));

        // Dialog sandi tampil dan siswa bisa masuk dengan sandi awal itu
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        auth()->logout();
        $this->post(route('login.store'), ['username' => '0123456789', 'password' => $s->initial_password])->assertRedirect();
        $this->assertAuthenticatedAs($s);
        auth()->logout();

        // Guru + wali kelas
        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'teacher', 'username' => 'budi.s', 'name' => 'Budi S', 'class_id' => $class->id])->assertSessionHasNoErrors();
        $t = $this->inSchool($school, fn () => User::where('username', 'budi.s')->first());
        $this->assertSame(8, strlen($t->initial_password));
        $this->assertSame($t->id, $this->inSchool($school, fn () => $class->fresh()->homeroom_teacher_id));

        // Username kembar (tanpa beda huruf besar/kecil), siswa tanpa rombel, peran tidak sah
        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'teacher', 'username' => 'BUDI.S', 'name' => 'Lain'])->assertSessionHasErrors('username');
        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'student', 'username' => '555', 'name' => 'Tanpa Kelas'])->assertSessionHasErrors('class_id');
        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'school_admin', 'username' => 'adm2', 'name' => 'X'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->post(route('admin.users.store'), ['role' => 'teacher', 'username' => 'a b', 'name' => 'X'])->assertSessionHasErrors('username');

        // Guru biasa tidak boleh
        $this->actingAs($teacher)->post(route('admin.users.store'), ['role' => 'student', 'username' => 'zzz1', 'name' => 'X', 'class_id' => $class->id])->assertForbidden();
    }
}
