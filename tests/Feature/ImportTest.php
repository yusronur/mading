<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ImportJob;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Import\SpreadsheetExporter;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ImportTest extends TestCase
{
    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        app(SpreadsheetExporter::class)->writeFile($path, $headers, $rows);

        return new UploadedFile($path, 'data.xlsx', null, null, true);
    }

    private function admin($school): User
    {
        return $this->inSchool($school, fn () => User::where('username', 'admin')->first());
    }

    public function test_student_import_preview_process_and_reimport_without_duplicates(): void
    {
        $school = $this->makeSchool();
        $admin = $this->admin($school);
        $headers = ['nisn', 'nama', 'jk', 'rombel'];

        $file = $this->xlsx($headers, [
            ['0123456789', 'Ahmad Fauzi', 'L', '7A'],
            ['0123456790', 'Siti Aminah', 'P', '7B'],
            ['123', 'NISN Salah', 'P', '7B'],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'students', 'file' => $file]);
        $import = $this->inSchool($school, fn () => ImportJob::firstOrFail());
        $response->assertRedirect(route('admin.imports.show', $import));

        // Pratinjau belum menyimpan apa pun
        $this->assertSame(0, $this->inSchool($school, fn () => User::where('role', 'student')->count()));
        $this->actingAs($admin)->get(route('admin.imports.show', $import))
            ->assertInertia(fn ($p) => $p->where('summary.new', 2)->where('summary.error', 1));

        $this->actingAs($admin)->post(route('admin.imports.process', $import))->assertSessionHas('success');

        $this->inSchool($school, function () use ($import) {
            $import->refresh();
            $this->assertSame('done', $import->status);
            $this->assertSame(2, $import->created_count);
            $this->assertSame(1, $import->skipped_count);
            $this->assertCount(2, $import->credentials);
            $this->assertSame(['7A', '7B'], SchoolClass::orderBy('name')->pluck('name')->all());
            $this->assertTrue(User::where('username', '0123456789')->first()->must_change_password);
        });

        // Siswa baru bisa login dengan sandi awal
        $cred = $this->inSchool($school, fn () => $import->fresh()->credentials[0]);
        auth()->logout();
        $this->post('/masuk', ['username' => $cred['username'], 'password' => $cred['password']])
            ->assertRedirect(route('password.change'));
        auth()->logout();

        // Impor ulang: nama berubah dan pindah rombel, tidak menggandakan
        $again = $this->xlsx($headers, [['0123456789', 'Ahmad Fauzi Rahman', 'L', '7B']]);
        $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'students', 'file' => $again]);
        $second = $this->inSchool($school, fn () => ImportJob::latest()->first());
        $this->actingAs($admin)->get(route('admin.imports.show', $second))->assertInertia(fn ($p) => $p->where('summary.update', 1));
        $this->actingAs($admin)->post(route('admin.imports.process', $second));

        $this->inSchool($school, function () {
            $this->assertSame(2, User::where('role', 'student')->count());
            $ahmad = User::where('username', '0123456789')->first();
            $this->assertSame('Ahmad Fauzi Rahman', $ahmad->name);
            $this->assertSame(['7B'], $ahmad->classes()->pluck('name')->all());
        });

        // Daftar akun bisa diunduh lalu dihapus dari server
        $this->actingAs($admin)->get(route('admin.imports.credentials', $import))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($admin)->delete(route('admin.imports.destroy', $import));
        $this->assertNull($this->inSchool($school, fn () => $import->fresh()->credentials));
    }

    public function test_teacher_import_sets_homeroom_and_rejects_username_used_by_student(): void
    {
        $school = $this->makeSchool();
        $admin = $this->admin($school);
        $this->makeUser($school, User::ROLE_STUDENT, ['username' => 'bentrok']);
        $class = $this->inSchool($school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '8C']));

        $file = $this->xlsx(['nip_atau_username', 'nama', 'mapel', 'wali_kelas'], [
            ['198501012010011001', 'Budi Santoso', 'Matematika', '8C'],
            ['bentrok', 'Guru Bentrok', '', ''],
        ]);

        $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'teachers', 'file' => $file]);
        $import = $this->inSchool($school, fn () => ImportJob::firstOrFail());
        $this->actingAs($admin)->get(route('admin.imports.show', $import))
            ->assertInertia(fn ($p) => $p->where('summary.new', 1)->where('summary.error', 1));
        $this->actingAs($admin)->post(route('admin.imports.process', $import));

        $this->inSchool($school, function () use ($class) {
            $budi = User::where('username', '198501012010011001')->first();
            $this->assertSame('teacher', $budi->role);
            $this->assertSame(['Matematika'], $budi->profile['subjects']);
            $this->assertSame($budi->id, $class->fresh()->homeroom_teacher_id);
        });
    }

    public function test_student_import_requires_active_year(): void
    {
        $school = $this->makeSchool();
        $admin = $this->admin($school);
        $this->inSchool($school, fn () => AcademicYear::query()->update(['is_active' => false]));

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'type' => 'students', 'file' => $this->xlsx(['nisn', 'nama', 'rombel'], [['0123456789', 'A', '7A']]),
        ])->assertSessionHas('error');
    }

    public function test_templates_download(): void
    {
        $school = $this->makeSchool();
        $admin = $this->admin($school);

        $this->actingAs($admin)->get(route('admin.imports.template', 'students'))->assertOk();
        $this->actingAs($admin)->get(route('admin.imports.template', 'teachers'))->assertOk();
    }

    /** Blueprint Fase 0: impor 900 siswa uji < 1 menit tanpa duplikasi. */
    #[Group('slow')]
    public function test_import_900_students_under_one_minute(): void
    {
        $school = $this->makeSchool();
        $admin = $this->admin($school);
        $path = storage_path('app/uji-900.xlsx');
        $this->artisan('madingo:sample-import', ['type' => 'students', '--rows' => 900, '--path' => $path])->assertSuccessful();

        $start = microtime(true);
        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'type' => 'students', 'file' => new UploadedFile($path, 'uji.xlsx', null, null, true),
        ]);
        $import = $this->inSchool($school, fn () => ImportJob::firstOrFail());
        $this->actingAs($admin)->post(route('admin.imports.process', $import));
        $elapsed = microtime(true) - $start;

        $this->inSchool($school, function () {
            $this->assertSame(900, User::where('role', 'student')->count());
            $this->assertSame(27, SchoolClass::count());
        });
        fwrite(STDERR, sprintf("\n[impor 900 siswa: %.1f detik]\n", $elapsed));
        $this->assertLessThan(60, $elapsed);
        @unlink($path);
    }
}
