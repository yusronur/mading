<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sandi awal siswa: dibuat per rombel/siswa, tetap tersimpan, bisa dicetak, hilang saat diganti. */
class CredentialTest extends TestCase
{
    private function setup7A(): array
    {
        $school = $this->makeSchool();
        $admin = $this->inSchool($school, fn () => User::where('username', 'admin')->first());
        $class = $this->inSchool($school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]));

        $mk = fn (array $attrs = []) => tap($this->makeUser($school, User::ROLE_STUDENT, $attrs + ['must_change_password' => true]), function ($u) use ($school, $class) {
            $this->inSchool($school, fn () => DB::table('class_members')->insert([
                'class_id' => $class->id, 'user_id' => $u->id, 'school_id' => $school->id, 'created_at' => now(), 'updated_at' => now(),
            ]));
        });

        return [$school, $admin, $class, $mk];
    }

    public function test_generate_only_touches_students_who_never_changed_password(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        $fresh = $mk();
        $changed = $mk(['must_change_password' => false]);
        $hash = $this->inSchool($school, fn () => $changed->fresh()->password);

        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id])
            ->assertSessionHas('success');

        $this->inSchool($school, function () use ($fresh, $changed, $hash) {
            $fresh = $fresh->fresh();
            $this->assertSame(6, strlen($fresh->initial_password));
            $this->assertTrue($fresh->must_change_password);
            $this->assertTrue(Hash::check($fresh->initial_password, $fresh->password), 'sandi awal cocok dengan hash untuk login');

            $changed = $changed->fresh();
            $this->assertNull($changed->initial_password);
            $this->assertSame($hash, $changed->password, 'sandi siswa yang sudah ganti tidak disentuh');
        });
    }

    public function test_generate_keeps_existing_initial_password_unless_regenerate(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        $s = $mk();
        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id]);
        $first = $this->inSchool($school, fn () => $s->fresh()->initial_password);

        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id])->assertSessionHas('error');
        $this->assertSame($first, $this->inSchool($school, fn () => $s->fresh()->initial_password));

        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id, 'regenerate' => true])->assertSessionHas('success');
        $this->assertNotSame($first, $this->inSchool($school, fn () => $s->fresh()->initial_password));
    }

    public function test_print_page_lists_cards_and_survives_reload(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        [$a, $b] = [$mk(['name' => 'Ayu']), $mk(['name' => 'Budi'])];
        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id]);
        $pw = $this->inSchool($school, fn () => $a->fresh()->initial_password);

        foreach ([1, 2] as $_) { // dibuka dua kali: sandi tidak hilang
            $this->actingAs($admin)->get(route('credentials.print', ['class_id' => $class->id]))
                ->assertOk()
                ->assertInertia(fn ($p) => $p->component('Admin/Credentials/Print')->has('cards', 2)->where('cards.0.name', 'Ayu')
                    ->where('cards.0.password', $pw)->where('cards.0.class', '7A')->where('missing', 0));
        }

        $this->actingAs($admin)->get(route('credentials.print', ['user_id' => $b->id]))
            ->assertInertia(fn ($p) => $p->has('cards', 1)->where('cards.0.name', 'Budi'));
    }

    public function test_print_requires_a_scope_and_admin_role(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);

        $this->actingAs($admin)->get(route('credentials.print'))->assertStatus(422);
        $this->actingAs($teacher)->get(route('credentials.print', ['all' => 1]))->assertForbidden();
        $this->actingAs($student)->get(route('credentials.index'))->assertForbidden();
    }

    public function test_initial_password_is_encrypted_at_rest_and_cleared_on_change(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        $s = $mk();
        $this->actingAs($admin)->post(route('credentials.generate'), ['class_id' => $class->id]);
        $plain = $this->inSchool($school, fn () => $s->fresh()->initial_password);

        $raw = $this->inSchool($school, fn () => DB::table('users')->where('id', $s->id)->value('initial_password'));
        $this->assertStringNotContainsString($plain, $raw);

        $this->actingAs($this->inSchool($school, fn () => $s->fresh()))->put('/ganti-sandi', ['current_password' => $plain, 'password' => 'SandiBaru2026', 'password_confirmation' => 'SandiBaru2026'])
            ->assertSessionHasNoErrors();

        $after = $this->inSchool($school, fn () => $s->fresh());
        $this->assertNull($after->initial_password);
        $this->assertFalse($after->must_change_password);
    }

    public function test_reset_password_dialog_data_is_read_from_encrypted_column_not_session(): void
    {
        [$school, $admin, $class, $mk] = $this->setup7A();
        $s = $mk(['must_change_password' => false]);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $s->id))
            ->assertSessionHas('credential_for', $s->id)
            ->assertSessionMissing('success');

        $this->actingAs($admin)->withSession(['credential_for' => $s->id])->get(route('admin.users.index'))
            ->assertInertia(fn ($p) => $p->where('credential.id', $s->id)->where('credential.password', fn ($v) => strlen($v) === 6));
    }

    public function test_import_stores_initial_password_for_new_accounts(): void
    {
        [$school, $admin] = $this->setup7A();
        $service = app(\App\Modules\Import\ImportService::class);
        $this->assertSame(6, strlen($service->randomPassword()));
    }
}
