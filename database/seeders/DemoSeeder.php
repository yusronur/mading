<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh fiktif untuk mencoba aplikasi (bukan data siswa asli):
 * 2 guru, 2 rombel, 10 siswa, 1 papan berisi beberapa kartu.
 * Semua akun demo memakai sandi "demo1234" dan tidak wajib ganti sandi.
 */
class DemoSeeder
{
    public const PASSWORD = 'demo1234';

    public function __construct(private TenantContext $tenant) {}

    public function run(School $school): array
    {
        return $this->tenant->run($school, function () {
            $year = AcademicYear::active();

            $teachers = collect([
                ['guru.demo', 'Bu Rina Demo', 'P'],
                ['guru.demo2', 'Pak Budi Demo', 'L'],
            ])->map(fn ($t) => User::firstOrCreate(['username' => $t[0]], [
                'name' => $t[1], 'gender' => $t[2], 'password' => self::PASSWORD,
                'role' => User::ROLE_TEACHER, 'must_change_password' => false,
            ]));

            $classes = collect(['7A', '7B'])->map(fn ($name) => SchoolClass::firstOrCreate(
                ['academic_year_id' => $year->id, 'name' => $name],
                ['grade' => 7]
            ));

            $names = ['Aditya Pratama', 'Bunga Lestari', 'Cahya Ramadhan', 'Dewi Anggraini', 'Eka Saputra',
                'Fitri Handayani', 'Galih Nugroho', 'Hana Safitri', 'Irfan Maulana', 'Jihan Aulia'];

            $students = collect($names)->map(function ($name, $i) use ($classes) {
                $user = User::firstOrCreate(['username' => sprintf('99000000%02d', $i + 1)], [
                    'name' => $name, 'gender' => $i % 2 ? 'P' : 'L', 'password' => self::PASSWORD,
                    'role' => User::ROLE_STUDENT, 'must_change_password' => false,
                ]);
                $class = $classes[$i < 5 ? 0 : 1];
                DB::table('class_members')->insertOrIgnore([
                    'class_id' => $class->id, 'user_id' => $user->id, 'school_id' => $user->school_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $user;
            });

            $board = Board::firstOrCreate(['title' => 'Kenalan Yuk! (Demo)'], [
                'owner_id' => $teachers[0]->id,
                'class_id' => $classes[0]->id,
                'description' => 'Tulis nama panggilan, hobi, dan cita-citamu.',
                'visibility' => 'class',
                'format' => 'wall',
            ]);

            if ($board->posts()->count() === 0) {
                $board->posts()->create(['author_id' => $teachers[0]->id, 'title' => 'Selamat datang!', 'body' => 'Halo 7A! Ceritakan sedikit tentang dirimu di papan ini ya.', 'is_pinned' => true]);
                $board->posts()->create(['author_id' => $students[0]->id, 'title' => 'Adit', 'body' => 'Hobi main bola, cita-cita jadi insinyur.']);
                $board->posts()->create(['author_id' => $students[1]->id, 'title' => 'Bunga', 'body' => 'Suka menggambar dan baca komik.']);
            }

            return [
                'teachers' => $teachers->pluck('username')->all(),
                'students' => $students->pluck('username')->all(),
                'board_code' => $board->code,
            ];
        });
    }
}
