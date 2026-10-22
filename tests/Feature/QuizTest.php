<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tahap 12: kuis mandiri, penilaian di server, laporan. */
class QuizTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private User $outsider;

    private SchoolClass $class;

    private Quiz $quiz;

    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->outsider = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Luar']);

        $this->inSchool($this->school, function () {
            $this->class = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]);
            foreach ([$this->ani, $this->budi] as $u) {
                DB::table('class_members')->insert(['class_id' => $this->class->id, 'user_id' => $u->id, 'school_id' => $u->school_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $bank = QuestionBank::create(['owner_id' => $this->teacher->id, 'name' => 'IPA']);
            $mk = fn ($type, $data, $points = 1) => $bank->questions()->create(['author_id' => $this->teacher->id, 'type' => $type, 'prompt' => '<p>Soal '.$type.'</p>', 'data' => $data, 'points' => $points, 'explanation' => '<p>Karena begitu.</p>']);
            $this->q = [
                'mc' => $mk('mc', ['options' => [['id' => 'a', 'text' => 'Satu'], ['id' => 'b', 'text' => 'Dua']], 'answer' => 'b'], 2),
                'mcm' => $mk('mcm', ['options' => [['id' => 'a', 'text' => 'X'], ['id' => 'b', 'text' => 'Y'], ['id' => 'c', 'text' => 'Z']], 'answers' => ['a', 'c']]),
                'tf' => $mk('tf', ['answer' => false]),
                'short' => $mk('short', ['answers' => ['Fotosintesis']]),
            ];
        });

        $this->actingAs($this->teacher)->post(route('quizzes.store'), ['title' => 'Ulangan Bab 1'])->assertRedirect();
        $this->quiz = $this->inSchool($this->school, fn () => Quiz::firstOrFail());
        $this->saveQuiz();
    }

    private function saveQuiz(array $settings = []): void
    {
        $this->actingAs($this->teacher)->put(route('quizzes.update', $this->quiz), [
            'title' => 'Ulangan Bab 1',
            'settings' => $settings + ['shuffle_questions' => true, 'shuffle_options' => true, 'duration_minutes' => 30, 'attempts' => 1, 'scoring' => 'best', 'review' => 'after_submit', 'show_score' => true, 'kkm' => 75],
            'questions' => array_map(fn ($q) => ['id' => $q->id, 'points' => null], array_values($this->q)),
        ])->assertSessionHasNoErrors();
    }

    private function assign(array $extra = []): QuizSession
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), $extra + [
            'class_ids' => [$this->class->id], 'duration_minutes' => 30, 'closes_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        return $this->inSchool($this->school, fn () => QuizSession::latest()->firstOrFail());
    }

    private function start(User $u, QuizSession $s): QuizAttempt
    {
        $this->actingAs($u)->post(route('take.start', $s))->assertRedirect();

        return $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $u->id)->orderByDesc('attempt_no')->firstOrFail());
    }

    private function answer(User $u, QuizAttempt $a, string $key, mixed $response)
    {
        return $this->actingAs($u)->postJson(route('take.answer', $a), ['question_id' => $this->q[$key]->id, 'response' => $response]);
    }

    public function test_full_flow_with_server_grading_and_no_key_leak(): void
    {
        $s = $this->assign();
        $this->assertSame(5, $s->maxScore());

        // Siswa melihat kuis di beranda; siswa kelas lain tidak
        $this->actingAs($this->ani)->get(route('student.dashboard'))->assertInertia(fn ($p) => $p->has('quizzes', 1)->where('quizzes.0.mine', 'none'));
        $this->actingAs($this->outsider)->post(route('take.start', $s))->assertSessionHas('error');

        $a = $this->start($this->ani, $s);
        $page = $this->actingAs($this->ani)->get(route('take.attempt', $a))->assertOk();
        $json = json_encode($page->viewData('page')['props']['questions']);
        foreach (['"answer"', '"answers"', 'Fotosintesis', 'Karena begitu', 'correct'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "kunci bocor: {$secret}");
        }

        $this->answer($this->ani, $a, 'mc', 'b')->assertOk()->assertJsonMissingPath('correct');
        $this->answer($this->ani, $a, 'mcm', ['c', 'a'])->assertOk();
        $this->answer($this->ani, $a, 'tf', 'true')->assertOk(); // salah
        $this->answer($this->ani, $a, 'short', '  fotosintesis ')->assertOk();
        $this->answer($this->ani, $a, 'mc', 'z')->assertStatus(422);

        $this->actingAs($this->ani)->post(route('take.submit', $a))->assertRedirect(route('take.result', $a));
        $a = $this->inSchool($this->school, fn () => $a->fresh());
        $this->assertSame(4.0, $a->score);
        $this->assertSame(80, $a->grade());

        // Setelah dikumpulkan tidak bisa mengubah jawaban; kesempatan habis
        $this->answer($this->ani, $a, 'tf', 'false')->assertStatus(422);
        $this->actingAs($this->ani)->post(route('take.start', $s))->assertSessionHas('error', 'Kesempatan mengerjakan sudah habis.');

        // Pembahasan tampil setelah kumpul
        $this->actingAs($this->ani)->get(route('take.result', $a))->assertInertia(fn ($p) => $p->where('canReview', true)->where('attempt.grade', 80)->has('review', 4));
        // Siswa lain tidak bisa membuka lembar Ani
        $this->actingAs($this->budi)->get(route('take.result', $a))->assertForbidden();
    }

    public function test_deadline_is_enforced_by_server(): void
    {
        $s = $this->assign(['duration_minutes' => 10]);
        $a = $this->start($this->ani, $s);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $a->deadline_at->getTimestamp(), 5);
        $this->answer($this->ani, $a, 'mc', 'b')->assertOk();

        $this->travel(11)->minutes();
        $this->answer($this->ani, $a, 'tf', 'false')->assertStatus(422)->assertJsonPath('submitted', true);
        $a = $this->inSchool($this->school, fn () => $a->fresh());
        $this->assertNotNull($a->submitted_at);
        $this->assertSame(2.0, $a->score, 'jawaban setelah waktu habis tidak dihitung');
    }

    public function test_review_after_close_and_multiple_attempts_best_score(): void
    {
        $this->saveQuiz(['attempts' => 2, 'review' => 'after_close', 'shuffle_questions' => false]);
        $s = $this->assign();

        $a1 = $this->start($this->ani, $s);
        $this->answer($this->ani, $a1, 'mc', 'a');
        $this->actingAs($this->ani)->post(route('take.submit', $a1));
        $this->actingAs($this->ani)->get(route('take.result', $a1))->assertInertia(fn ($p) => $p->where('canReview', false)->has('review', 0));

        $a2 = $this->start($this->ani, $s);
        $this->assertSame(2, $a2->attempt_no);
        $this->answer($this->ani, $a2, 'mc', 'b');
        $this->actingAs($this->ani)->post(route('take.submit', $a2));

        $this->actingAs($this->teacher)->put(route('assignments.update', $s), ['action' => 'close'])->assertSessionHas('success');
        $this->actingAs($this->ani)->get(route('take.result', $a1))->assertInertia(fn ($p) => $p->where('canReview', true));

        $this->actingAs($this->teacher)->get(route('assignments.show', $s))->assertInertia(fn ($p) => $p->component('Quiz/Report')
            ->where('report.rows.0.name', 'Ani')->where('report.rows.0.grade', 40)->where('report.rows.0.attempts', 2)
            ->where('report.rows.1.status', 'none')->where('report.summary.done', 1)->has('report.remedial', 2));
    }

    public function test_snapshot_keeps_grading_when_bank_changes_and_item_analysis(): void
    {
        $s = $this->assign();
        // Guru mengubah kunci di bank setelah ditugaskan
        $this->inSchool($this->school, fn () => $this->q['mc']->update(['data' => ['options' => [['id' => 'a', 'text' => 'Satu'], ['id' => 'b', 'text' => 'Dua']], 'answer' => 'a']]));

        foreach ([[$this->ani, 'b'], [$this->budi, 'a']] as [$u, $resp]) {
            $a = $this->start($u, $s);
            $this->answer($u, $a, 'mc', $resp);
            $this->actingAs($u)->post(route('take.submit', $a));
        }
        $this->assertSame(2.0, $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $this->ani->id)->value('score')) + 0.0);

        $this->actingAs($this->teacher)->get(route('assignments.show', $s))->assertInertia(function ($p) {
            $items = collect($p->toArray()['props']['report']['items'])->keyBy('type');
            $this->assertSame(0.5, $items['mc']['p']);
            $this->assertSame(1, collect($items['mc']['distribution'])->firstWhere('label', 'B')['count']);
            $this->assertSame('Sukar', $items['tf']['level']);
        });

        $x = $this->actingAs($this->teacher)->get(route('assignments.export', $s));
        $x->assertOk();
        $this->assertStringStartsWith('PK', $x->streamedContent());
    }

    public function test_access_control_and_reset(): void
    {
        $s = $this->assign();
        $other = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->actingAs($other)->get(route('assignments.show', $s))->assertForbidden();
        $this->actingAs($other)->get(route('quizzes.edit', $this->quiz))->assertForbidden();
        $this->actingAs($this->ani)->get(route('assignments.show', $s))->assertForbidden();

        // Kode kuis bisa dipakai di kotak "Masukkan kode"
        $this->actingAs($this->ani)->post(route('boards.join'), ['code' => strtolower($s->code)])->assertRedirect(route('take.show', $s));

        $a = $this->start($this->ani, $s);
        $this->actingAs($this->ani)->post(route('take.submit', $a));
        $this->actingAs($this->teacher)->post(route('assignments.reset', [$s, $this->ani]))->assertSessionHas('success');
        $this->start($this->ani, $s);

        // Kuis yang sudah dikerjakan tidak bisa dihapus
        $this->actingAs($this->teacher)->delete(route('quizzes.destroy', $this->quiz))->assertSessionHas('error');
    }

    public function test_teacher_quiz_index_and_question_ownership(): void
    {
        $this->assign();
        $foreign = $this->inSchool($this->school, function () {
            $other = User::where('role', 'student')->first();
            $bank = QuestionBank::create(['owner_id' => $this->ani->id, 'name' => 'x']);

            return $bank->questions()->create(['author_id' => $this->ani->id, 'type' => 'tf', 'prompt' => 'x', 'data' => ['answer' => true]]);
        });
        $this->actingAs($this->teacher)->put(route('quizzes.update', $this->quiz), [
            'title' => 'Ulangan', 'settings' => ['duration_minutes' => 30, 'attempts' => 1, 'scoring' => 'best', 'review' => 'never', 'kkm' => 70],
            'questions' => [['id' => $foreign->id], ['id' => $this->q['tf']->id, 'points' => 5]],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$this->q['tf']->id], $this->inSchool($this->school, fn () => $this->quiz->fresh()->questions->pluck('id')->all()), 'soal orang lain diabaikan');

        $this->actingAs($this->teacher)->get(route('quizzes.index'))->assertInertia(fn ($p) => $p->component('Quiz/Index')
            ->where('quizzes.0.questions_count', 1)->where('quizzes.0.sessions.0.students', 2)->where('quizzes.0.sessions.0.class_name', '7A'));
    }

    public function test_match_question_partial_credit_and_live_progress(): void
    {
        $match = $this->inSchool($this->school, function () {
            $bank = QuestionBank::firstOrFail();

            return $bank->questions()->create([
                'author_id' => $this->teacher->id, 'type' => 'match', 'prompt' => '<p>Jodohkan</p>', 'points' => 4,
                'data' => \App\Modules\Quiz\QuestionData::normalize('match', ['pairs' => [
                    ['left' => 'Jakarta', 'right' => 'Indonesia'], ['left' => 'Tokyo', 'right' => 'Jepang'],
                    ['left' => 'Seoul', 'right' => 'Korea'], ['left' => 'Hanoi', 'right' => 'Vietnam'],
                ], 'extras' => ['Laos']]),
            ]);
        });
        $this->q['match'] = $match;
        $this->saveQuiz(['shuffle_questions' => false]);
        $s = $this->assign();
        $this->assertSame(9, $s->maxScore());

        $a = $this->start($this->ani, $s);
        $page = $this->actingAs($this->ani)->get(route('take.attempt', $a))->assertOk();
        $q = collect($page->viewData('page')['props']['questions'])->firstWhere('type', 'match');
        $this->assertCount(4, $q['lefts']);
        $this->assertCount(5, $q['rights'], 'empat jawaban + satu pengecoh');
        $this->assertStringNotContainsString('right_id', json_encode($q));

        $rights = $this->inSchool($this->school, fn () => collect($match->fresh()->data['rights'])->pluck('id', 'text'));
        $pairs = $match->data['pairs'];
        // 2 dari 4 pasangan benar
        $resp = [$pairs[0]['id'] => $rights['Indonesia'], $pairs[1]['id'] => $rights['Jepang'], $pairs[2]['id'] => $rights['Vietnam']];
        $this->answer($this->ani, $a, 'match', $resp)->assertOk();
        // Satu kartu kanan tidak boleh dipakai dua kali; id asing ditolak
        $this->answer($this->ani, $a, 'match', [$pairs[0]['id'] => $rights['Indonesia'], $pairs[1]['id'] => $rights['Indonesia']])->assertStatus(422);
        $this->answer($this->ani, $a, 'match', ['zzz' => $rights['Indonesia']])->assertStatus(422);

        // Pantauan langsung guru: siswa tampak sedang mengerjakan dengan skor sementara
        $live = $this->actingAs($this->teacher)->getJson(route('assignments.progress', $s))->assertOk()->json();
        $row = collect($live['rows'])->firstWhere('name', 'Ani');
        $this->assertSame('working', $row['status']);
        $this->assertSame(1, $row['answered']);
        $this->assertSame(5, $row['total'], 'jumlah soal di tugas');
        $this->assertEquals(2.0, $row['score_now'], 'dua dari empat pasangan x 4 poin / 4 = 2');
        $this->assertSame(22, $row['grade_now']);
        $this->assertSame('none', collect($live['rows'])->firstWhere('name', 'Budi')['status']);
        $this->assertSame(1, $live['summary']['working']);

        // Hanya pengampu yang boleh memantau
        $other = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->actingAs($other)->getJson(route('assignments.progress', $s))->assertForbidden();
        $this->actingAs($this->ani)->getJson(route('assignments.progress', $s))->assertForbidden();

        $this->actingAs($this->ani)->post(route('take.submit', $a))->assertRedirect();
        $a = $this->inSchool($this->school, fn () => $a->fresh());
        $this->assertEquals(2.0, $a->score);

        $done = $this->actingAs($this->teacher)->getJson(route('assignments.progress', $s))->json();
        $this->assertSame('done', collect($done['rows'])->firstWhere('name', 'Ani')['status']);

        // Analisis butir: pasangan yang benar dihitung per pasangan
        $report = $this->actingAs($this->teacher)->get(route('assignments.show', $s))->viewData('page')['props']['report'];
        $item = collect($report['items'])->firstWhere('type', 'match');
        $this->assertSame(['Jakarta', 'Tokyo', 'Seoul', 'Hanoi'], array_map(fn ($d) => substr($d['label'], 3), $item['distribution']));
        $this->assertSame([1, 1, 0, 0], array_column($item['distribution'], 'count'), 'Seoul salah jodoh, Hanoi tidak dijawab');
    }

    public function test_report_loads_when_student_started_but_answered_nothing(): void
    {
        $s = $this->assign();
        $this->start($this->ani, $s);

        $this->actingAs($this->teacher)->get(route('assignments.show', $s))->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.rows.0.status', 'working')->where('report.rows.0.answered', 0));
        $this->actingAs($this->teacher)->getJson(route('assignments.progress', $s))->assertOk()->assertJsonPath('rows.0.answered', 0);
    }

    public function test_public_quiz_by_code_for_any_student_without_class(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), [
            'public' => true, 'duration_minutes' => 30, 'closes_at' => now()->addHour()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();
        $s = $this->inSchool($this->school, fn () => QuizSession::latest()->firstOrFail());
        $this->assertNull($s->class_id);

        // Siswa di luar rombel membuka lewat kode, lalu mengerjakan
        $this->actingAs($this->outsider)->post(route('boards.join'), ['code' => $s->code])->assertRedirect(route('take.show', $s));
        $this->actingAs($this->outsider)->get(route('take.show', $s))->assertOk();
        $a = $this->start($this->outsider, $s);
        $this->actingAs($this->outsider)->post(route('take.submit', $a))->assertRedirect();

        // Muncul di laporan guru dan di beranda siswa itu
        $this->actingAs($this->teacher)->get(route('assignments.show', $s))
            ->assertInertia(fn ($p) => $p->where('session.class_name', 'Publik (kode)')->has('report.rows', 1)->where('report.rows.0.name', 'Luar'));
        $this->actingAs($this->outsider)->get(route('student.dashboard'))->assertInertia(fn ($p) => $p->has('quizzes', 1));

        // Tanpa rombel dan tanpa publik tetap ditolak
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), ['duration_minutes' => 30, 'closes_at' => now()->addHour()->format('Y-m-d H:i')])->assertSessionHasErrors('class_ids');
    }

    public function test_duration_is_required_and_each_attempt_gets_a_fresh_full_duration(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), [
            'class_ids' => [$this->class->id], 'closes_at' => now()->addDays(7)->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('duration_minutes');
        $this->actingAs($this->teacher)->put(route('quizzes.update', $this->quiz), [
            'title' => 'X', 'settings' => ['duration_minutes' => null, 'attempts' => 2, 'scoring' => 'best', 'review' => 'never', 'kkm' => 75],
            'questions' => array_map(fn ($q) => ['id' => $q->id, 'points' => null], array_values($this->q)),
        ])->assertSessionHasErrors('settings.duration_minutes');

        $this->saveQuiz(['attempts' => 2]);
        $s = $this->assign(['duration_minutes' => 20, 'closes_at' => now()->addDays(7)->format('Y-m-d H:i')]);
        $this->assertSame(20, $s->settings['duration_minutes']);

        $a1 = $this->start($this->ani, $s);
        // Tenggat 7 hari tidak dipakai sebagai batas waktu: batasnya mulai + 20 menit
        $this->assertEqualsWithDelta(20 * 60, $a1->started_at->diffInSeconds($a1->deadline_at), 2);

        // Percobaan pertama habis waktu; percobaan kedua mendapat 20 menit penuh sejak ia dimulai
        $this->travel(25)->minutes();
        $a2 = $this->start($this->ani, $s);
        $this->assertNotSame($a1->id, $a2->id);
        $this->assertEqualsWithDelta(20 * 60, $a2->started_at->diffInSeconds($a2->deadline_at), 2);
        $this->assertTrue($a2->deadline_at->gt(now()->addMinutes(19)));
    }

    public function test_guest_flow_is_locked_to_one_quiz(): void
    {
        $post = fn ($guests) => $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), [
            'public' => true, 'allow_guests' => $guests, 'duration_minutes' => 30, 'closes_at' => now()->addHour()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();
        $post(false);
        $closedToGuests = $this->inSchool($this->school, fn () => QuizSession::orderByDesc('id')->firstOrFail());
        $post(true);
        $open = $this->inSchool($this->school, fn () => QuizSession::orderByDesc('id')->firstOrFail());
        $this->assertNotSame($closedToGuests->id, $open->id);

        auth()->logout();
        // Tanpa izin tamu: ditolak; kode salah: ditolak
        $this->post(route('guest.store'), ['code' => $closedToGuests->code, 'name' => 'Tamu A'])->assertSessionHasErrors('code');
        $this->post(route('guest.store'), ['code' => 'ZZZZZZ', 'name' => 'Tamu A'])->assertSessionHasErrors('code');
        $this->post(route('guest.store'), ['code' => $open->code, 'name' => 'x'])->assertSessionHasErrors('name');
        $this->assertGuest();

        // Masuk sebagai tamu; nama kembar diberi nomor
        $this->post(route('guest.store'), ['code' => $open->code, 'name' => '  <b>Dewi</b>  Ayu '])->assertRedirect(route('take.show', $open));
        $guest = auth()->user();
        $this->assertSame(User::ROLE_GUEST, $guest->role);
        $this->assertSame('Dewi Ayu', $guest->name);

        // Terkunci: tidak bisa membuka halaman lain atau kuis lain
        foreach (['student.dashboard', 'profile.edit', 'teacher.dashboard', 'banks.index', 'admin.dashboard'] as $r) {
            $this->get(route($r))->assertRedirect(route('home'));
        }
        $this->get(route('take.show', $closedToGuests))->assertRedirect(route('home'));
        $this->post(route('take.start', $closedToGuests))->assertRedirect();
        $this->assertSame(0, $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $guest->id)->where('session_id', $closedToGuests->id)->count()));

        // Mengerjakan kuis yang diizinkan sampai kumpul
        $this->get(route('take.show', $open))->assertOk()->assertInertia(fn ($p) => $p->where('isGuest', true));
        $a = $this->inSchool($this->school, fn () => app(\App\Modules\Quiz\QuizEngine::class)->start($guest, $open));
        $this->post(route('take.submit', $a))->assertRedirect(route('take.result', $a));

        // Tamu lain: nama kembar bernomor; muncul di laporan guru sebagai Tamu
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();
        $this->post(route('guest.store'), ['code' => $open->code, 'name' => 'dewi ayu'])->assertRedirect();
        $this->assertSame('dewi ayu (2)', auth()->user()->name);

        $this->actingAs($this->teacher)->get(route('assignments.show', $open))
            ->assertInertia(fn ($p) => $p->has('report.rows', 1)->where('report.rows.0.guest', true)->where('report.rows.0.username', null));
        $this->actingAs($this->teacher)->get(route('admin.users.index'))->assertForbidden(); // guru bukan admin
    }
}
