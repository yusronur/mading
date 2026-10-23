<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Quiz;
use App\Models\QuestionBank;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tahap 20: data uji beban untuk skrip k6 (tests/load). Semua akun berawalan
 * "uji-beban-" di rombel "UJI-BEBAN" sehingga mudah dibersihkan dan tidak
 * tercampur dengan data sekolah.
 */
class LoadTestCommand extends Command
{
    protected $signature = 'madingo:loadtest {action : seed atau clean} {--school= : slug sekolah (bawaan: sekolah pertama)} {--students=200 : jumlah akun siswa} {--password= : sandi semua akun uji} {--force : izinkan di production}';

    protected $description = 'Siapkan atau hapus data uji beban k6 (akun siswa, rombel, papan, kuis live)';

    public const PREFIX = 'uji-beban-';

    public function handle(TenantContext $tenant): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Di production tambahkan --force. Sebaiknya jalankan di luar jam sekolah.');

            return self::FAILURE;
        }
        $school = $this->option('school') ? School::where('slug', $this->option('school'))->first() : School::orderBy('created_at')->first();
        if (! $school) {
            $this->error('Sekolah tidak ditemukan.');

            return self::FAILURE;
        }

        return $tenant->run($school, fn () => match ($this->argument('action')) {
            'seed' => $this->seed($school),
            'clean' => $this->clean(),
            default => tap(self::FAILURE, fn () => $this->error('Aksi harus seed atau clean.')),
        });
    }

    private function seed(School $school): int
    {
        $year = AcademicYear::active();
        if (! $year) {
            $this->error('Belum ada tahun ajaran aktif.');

            return self::FAILURE;
        }
        $this->clean(quiet: true);
        $n = max(1, min(2000, (int) $this->option('students')));
        $password = $this->option('password') ?: Str::password(12, symbols: false);
        $hash = Hash::make($password);
        $now = now();

        $data = DB::transaction(function () use ($year, $n, $hash, $now, $school) {
            $class = SchoolClass::create(['academic_year_id' => $year->id, 'name' => 'UJI-BEBAN', 'grade' => 7]);
            $teacher = User::create(['username' => self::PREFIX.'guru', 'name' => 'Guru Uji Beban', 'password' => $hash, 'role' => User::ROLE_TEACHER, 'status' => 'active', 'must_change_password' => false]);

            // Siswa dibuat massal (tanpa event model) agar cepat
            $rows = [];
            $members = [];
            for ($i = 1; $i <= $n; $i++) {
                $id = (string) Str::uuid7();
                $rows[] = ['id' => $id, 'school_id' => $school->id, 'username' => self::PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'name' => 'Siswa Uji '.$i,
                    'password' => $hash, 'role' => User::ROLE_STUDENT, 'status' => 'active', 'must_change_password' => false, 'created_at' => $now, 'updated_at' => $now];
                $members[] = ['class_id' => $class->id, 'user_id' => $id, 'school_id' => $school->id, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('users')->insert($chunk);
            }
            foreach (array_chunk($members, 500) as $chunk) {
                DB::table('class_members')->insert($chunk);
            }

            $board = Board::create(['owner_id' => $teacher->id, 'title' => 'Papan Uji Beban', 'visibility' => 'class', 'class_id' => $class->id, 'visitor_permission' => 'write']);

            $bank = QuestionBank::create(['owner_id' => $teacher->id, 'name' => 'Bank Uji Beban']);
            $quiz = Quiz::create(['owner_id' => $teacher->id, 'title' => 'Kuis Live Uji Beban', 'settings' => []]);
            $attach = [];
            for ($q = 1; $q <= 10; $q++) {
                $question = $bank->questions()->create(['author_id' => $teacher->id, 'type' => 'mc', 'prompt' => "<p>Soal uji {$q}: 2 + {$q} = ?</p>", 'points' => 1,
                    'data' => ['options' => collect(range(0, 3))->map(fn ($k) => ['id' => chr(97 + $k), 'text' => (string) (2 + $q + $k)])->all(), 'answer' => 'a']]);
                $attach[$question->id] = ['id' => (string) Str::uuid7(), 'position' => $q, 'points' => null, 'school_id' => $school->id];
            }
            $quiz->questions()->attach($attach);

            return ['school' => $school->slug, 'class_id' => $class->id, 'board_id' => $board->id, 'quiz_id' => $quiz->id, 'teacher' => $teacher->username, 'students' => $n, 'student_prefix' => self::PREFIX];
        });

        $data['password'] = $password;
        $path = storage_path('app/loadtest.json');
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        $this->info("Siap: {$n} siswa (".self::PREFIX.'0001 …), guru '.self::PREFIX.'guru, sandi: '.$password);
        $this->line('Data untuk k6 tersimpan di '.$path);
        $this->line('Bersihkan setelah selesai: php artisan madingo:loadtest clean');

        return self::SUCCESS;
    }

    private function clean(bool $quiet = false): int
    {
        $ids = DB::table('users')->where('username', 'like', self::PREFIX.'%')->pluck('id');
        $boards = Board::withTrashed()->whereIn('owner_id', $ids)->get();
        foreach ($boards as $b) {
            $b->forceDelete();
        }
        Quiz::withTrashed()->whereIn('owner_id', $ids)->get()->each->forceDelete();
        QuestionBank::withTrashed()->whereIn('owner_id', $ids)->get()->each->forceDelete();
        SchoolClass::where('name', 'UJI-BEBAN')->get()->each->delete();
        $n = DB::table('users')->whereIn('id', $ids)->delete();
        @unlink(storage_path('app/loadtest.json'));
        if (! $quiet) {
            $this->info("Data uji beban dihapus ({$n} akun).");
        }

        return self::SUCCESS;
    }
}
