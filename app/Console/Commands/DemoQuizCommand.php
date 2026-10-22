<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\School;
use App\Models\User;
use App\Modules\Quiz\QuestionData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Membuat bank soal + kuis contoh 10 soal langsung di database, untuk uji coba (kuis biasa maupun live). */
class DemoQuizCommand extends Command
{
    protected $signature = 'madingo:demo-quiz
        {username? : Username guru pemilik (bawaan: guru pertama yang ditemukan)}
        {--school= : ID sekolah (bawaan: sekolah pertama)}';

    protected $description = 'Membuat kuis contoh 10 soal untuk uji coba';

    /** [tipe, soal, pilihan, kunci, pembahasan, topik, poin] */
    private const ROWS = [
        ['mc', 'Ibu kota Indonesia saat ini adalah ...', ['Bandung', 'Jakarta', 'Surabaya', 'Medan'], 'b', 'Jakarta masih menjadi ibu kota yang berfungsi.', 'Umum', 1],
        ['mc', 'Hasil dari 7 x 8 adalah ...', ['54', '56', '58', '64'], 'b', '7 x 8 = 56.', 'Matematika', 1],
        ['mc', 'Planet terdekat dari Matahari adalah ...', ['Venus', 'Bumi', 'Merkurius', 'Mars'], 'c', 'Merkurius paling dekat dengan Matahari.', 'IPA', 1],
        ['mc', 'Lambang sila ke-3 Pancasila adalah ...', ['Bintang', 'Rantai', 'Pohon beringin', 'Kepala banteng'], 'c', 'Sila ke-3, Persatuan Indonesia, berlambang pohon beringin.', 'PPKn', 2],
        ['mc', 'Air membeku pada suhu ... derajat Celsius.', ['-10', '0', '50', '100'], 'b', 'Titik beku air adalah 0 derajat Celsius.', 'IPA', 1],
        ['mcm', 'Manakah yang termasuk bilangan prima?', ['2', '4', '7', '9'], ['a', 'c'], '2 dan 7 hanya habis dibagi 1 dan dirinya sendiri.', 'Matematika', 2],
        ['mcm', 'Manakah yang termasuk hewan mamalia?', ['Paus', 'Hiu', 'Kelelawar', 'Penyu'], ['a', 'c'], 'Paus dan kelelawar menyusui anaknya.', 'IPA', 2],
        ['tf', 'Matahari terbit dari arah barat.', [], 'false', 'Matahari terbit dari timur.', 'IPA', 1],
        ['tf', 'Proklamasi kemerdekaan Indonesia dibacakan pada tahun 1945.', [], 'true', 'Proklamasi dibacakan pada 17 Agustus 1945.', 'Sejarah', 1],
        ['short', 'Ibu kota Provinsi Jawa Timur adalah ...', [], 'Surabaya', 'Surabaya adalah ibu kota Jawa Timur.', 'Umum', 2],
    ];

    public function handle(TenantContext $tenant): int
    {
        $school = $this->option('school') ? School::find($this->option('school')) : School::first();
        if (! $school) {
            $this->error('Sekolah tidak ditemukan.');

            return self::FAILURE;
        }

        return $tenant->run($school, function () use ($school) {
            $teacher = $this->argument('username')
                ? User::where('username', $this->argument('username'))->first()
                : User::where('role', User::ROLE_TEACHER)->orderBy('id')->first();
            if (! $teacher) {
                $this->error('Guru tidak ditemukan. Sebutkan username guru: php artisan madingo:demo-quiz <username>');

                return self::FAILURE;
            }

            DB::transaction(function () use ($school, $teacher) {
                $bank = QuestionBank::create(['owner_id' => $teacher->id, 'name' => 'Contoh 10 Soal (uji coba)', 'subject' => 'Umum']);
                $ids = [];
                foreach (self::ROWS as $i => [$type, $prompt, $opts, $key, $why, $topic, $points]) {
                    $data = QuestionData::normalize($type, match ($type) {
                        'mc', 'mcm' => ['options' => $opts, 'correct' => (array) $key],
                        'tf' => ['correct' => $key],
                        'short' => ['answers' => [$key]],
                    });
                    $ids[] = $bank->questions()->create([
                        'author_id' => $teacher->id,
                        'type' => $type,
                        'prompt' => '<p>'.e($prompt).'</p>',
                        'explanation' => '<p>'.e($why).'</p>',
                        'data' => $data,
                        'tags' => [$topic],
                        'difficulty' => 1,
                        'points' => $points,
                        'position' => $i + 1,
                    ])->id;
                }

                $quiz = Quiz::create([
                    'owner_id' => $teacher->id,
                    'title' => 'Kuis Contoh 10 Soal',
                    'description' => 'Kuis contoh untuk uji coba.',
                    'settings' => ['duration_minutes' => 15, 'attempts' => 0, 'scoring' => 'best', 'review' => 'after_submit', 'kkm' => 70],
                ]);
                foreach ($ids as $i => $qid) {
                    $quiz->questions()->attach($qid, ['id' => (string) Str::uuid7(), 'position' => $i + 1, 'points' => null, 'school_id' => $quiz->school_id]);
                }
            });

            $this->components->info("Kuis \"Kuis Contoh 10 Soal\" (10 soal) dibuat untuk {$teacher->name} ({$teacher->username}).");

            return self::SUCCESS;
        }) ?? self::SUCCESS;
    }
}
