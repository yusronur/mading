<?php

namespace Tests\Feature;

use App\Events\LiveQuizUpdated;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\User;
use App\Modules\Quiz\LiveQuiz;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Tahap 13: kuis live (QZ-10..13, AUTH-07). */
class LiveQuizTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Quiz $quiz;

    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);

        $this->inSchool($this->school, function () {
            $bank = QuestionBank::create(['owner_id' => $this->teacher->id, 'name' => 'IPA']);
            $mk = fn ($type, $data, $points = 1) => $bank->questions()->create(['author_id' => $this->teacher->id, 'type' => $type, 'prompt' => '<p>Soal '.$type.'</p>', 'data' => $data, 'points' => $points]);
            $this->q = [
                'mc' => $mk('mc', ['options' => [['id' => 'a', 'text' => 'Satu'], ['id' => 'b', 'text' => 'Dua']], 'answer' => 'b'], 2),
                'tf' => $mk('tf', ['answer' => false]),
            ];
        });
        $this->actingAs($this->teacher)->post(route('quizzes.store'), ['title' => 'Kuis Live IPA']);
        $this->quiz = $this->inSchool($this->school, fn () => Quiz::firstOrFail());
        $this->actingAs($this->teacher)->put(route('quizzes.update', $this->quiz), [
            'title' => 'Kuis Live IPA',
            'settings' => ['duration_minutes' => 30, 'attempts' => 1, 'scoring' => 'best', 'review' => 'after_submit', 'kkm' => 75, 'shuffle_questions' => false],
            'questions' => [['id' => $this->q['mc']->id], ['id' => $this->q['tf']->id]],
        ])->assertSessionHasNoErrors();
    }

    private function startLive(array $opts = []): QuizSession
    {
        $this->actingAs($this->teacher)->post(route('live.store', $this->quiz), $opts + [
            'seconds' => 20, 'teams' => 0, 'allow_guests' => false, 'leaderboard' => true, 'shuffle_questions' => false,
        ])->assertRedirect();

        return $this->inSchool($this->school, fn () => QuizSession::where('mode', 'live')->orderByDesc('id')->firstOrFail());
    }

    private function state(User $u, QuizSession $s): array
    {
        return $this->actingAs($u)->getJson(route('live.state', $s))->assertOk()->json();
    }

    private function act(QuizSession $s, string $action, array $extra = [])
    {
        return $this->actingAs($this->teacher)->postJson(route('live.action', $s), ['action' => $action] + $extra);
    }

    public function test_full_game_with_speed_scoring_and_no_key_leak(): void
    {
        $s = $this->startLive();
        $this->assertNull($s->class_id);
        $this->actingAs($this->teacher)->get(route('live.host', $s))->assertOk()->assertInertia(fn ($p) => $p->component('Live/Host')->where('initial.phase', 'lobby'));

        // Mulai tanpa pemain ditolak
        $this->act($s, 'start')->assertStatus(422);

        // Siswa masuk lewat kode (jalur gabung yang sama dengan papan)
        $this->actingAs($this->ani)->post(route('boards.join'), ['code' => $s->code])->assertRedirect(route('take.show', $s));
        $this->actingAs($this->ani)->get(route('take.show', $s))->assertRedirect(route('live.play', $s));
        $this->actingAs($this->ani)->get(route('live.play', $s))->assertOk()->assertInertia(fn ($p) => $p->component('Live/Play')->where('initial.me.joined', true));
        $this->actingAs($this->budi)->get(route('live.play', $s))->assertOk();
        $this->assertCount(2, $this->state($this->teacher, $s)['host']['players']);

        $this->act($s, 'start')->assertOk()->assertJsonPath('phase', 'question')->assertJsonPath('index', 0);

        // Selama soal terbuka: tidak ada kunci, pembahasan, atau benar/salah
        $st = $this->state($this->ani, $s);
        $this->assertSame('question', $st['phase']);
        $this->assertNull($st['reveal']);
        $this->assertArrayNotHasKey('answer', $st['question']);
        $this->assertStringNotContainsString('"answer"', json_encode($st));
        $qid = $st['question']['id'];

        // Pilihan belum dibuka selama jeda baca 3 detik
        $this->actingAs($this->ani)->postJson(route('live.answer', $s), ['question_id' => $qid, 'response' => 'b'])->assertStatus(422);

        $this->travel(4)->seconds();   // 1 detik setelah dibuka
        $this->actingAs($this->ani)->postJson(route('live.answer', $s), ['question_id' => $qid, 'response' => 'b'])->assertOk();
        $this->actingAs($this->ani)->postJson(route('live.answer', $s), ['question_id' => $qid, 'response' => 'a'])->assertStatus(422); // sekali saja
        $this->assertFalse($this->state($this->ani, $s)['me']['result'] !== null, 'hasil belum terlihat sebelum pembahasan');

        // Layar host melihat jumlah pemilih tiap pilihan secara live; siswa tidak
        $this->assertSame([0, 1], $this->state($this->teacher, $s)['host_counts']);
        $this->assertArrayNotHasKey('host_counts', $this->state($this->ani, $s));

        $this->travel(9)->seconds();
        $this->actingAs($this->budi)->postJson(route('live.answer', $s), ['question_id' => $qid, 'response' => 'a'])->assertOk();

        // Semua sudah menjawab: pembahasan otomatis
        $st = $this->state($this->ani, $s);
        $this->assertSame('reveal', $st['phase']);
        $this->assertSame(['b'], $st['reveal']['key']);
        $this->assertSame([1, 1], array_column($st['reveal']['distribution'], 'count'));
        $this->assertTrue($st['me']['result']['correct']);
        // Benar 1 detik dari 20 detik, 2 poin: 1000 x 2 x (1 - 0,5 x 1/20) = 1950
        $this->assertEqualsWithDelta(1950, $st['me']['result']['live_score'], 5);
        $this->assertSame(0, $this->state($this->budi, $s)['me']['result']['live_score']);

        // Papan peringkat lalu soal 2
        $this->act($s, 'next')->assertJsonPath('phase', 'leaderboard')->assertJsonPath('leaderboard.players.0.name', 'Ani');
        $this->act($s, 'next')->assertJsonPath('phase', 'question')->assertJsonPath('index', 1);
        $q2 = $this->state($this->ani, $s)['question']['id'];

        // Waktu habis tanpa jawaban Budi: soal ditutup saat status berikutnya diminta
        $this->travel(5)->seconds();
        $this->actingAs($this->ani)->postJson(route('live.answer', $s), ['question_id' => $q2, 'response' => 'false'])->assertOk();
        $this->travel(30)->seconds();
        $this->actingAs($this->budi)->postJson(route('live.answer', $s), ['question_id' => $q2, 'response' => 'true'])->assertStatus(422);
        $this->assertSame('reveal', $this->state($this->budi, $s)['phase']);

        // Akhir: podium, percobaan dikumpulkan, masuk laporan kuis
        $this->act($s, 'next')->assertJsonPath('phase', 'leaderboard');
        $this->act($s, 'next')->assertJsonPath('phase', 'podium');
        $final = $this->state($this->ani, $s);
        $this->assertSame(1, $final['me']['rank']);
        $this->assertSame('closed', $this->inSchool($this->school, fn () => $s->fresh()->status()));
        $this->actingAs($this->teacher)->get(route('assignments.show', $s))->assertInertia(fn ($p) => $p
            ->where('session.class_name', 'Kuis live')->has('report.rows', 2)
            ->where('report.rows.0.name', 'Ani')->where('report.rows.0.grade', 100)
            ->where('report.rows.1.grade', 0));

        // Gamifikasi: ikut live (10 + bonus ketepatan 20) + juara 1 (30); nilai 100 = lencana
        $xp = fn (User $u) => $this->inSchool($this->school, fn () => \App\Models\UserLevel::where('user_id', $u->id)->value('xp'));
        $this->assertSame(60, $xp($this->ani));
        $this->assertSame(10, $xp($this->budi)); // skor 0: tanpa bonus juara
        $this->assertTrue($this->inSchool($this->school, fn () => \App\Models\UserBadge::where('user_id', $this->ani->id)->whereIn('badge', ['ace', 'podium'])->count() === 2));
    }

    public function test_guests_teams_kick_and_access_control(): void
    {
        $s = $this->startLive(['teams' => 2, 'allow_guests' => true]);
        $other = $this->makeUser($this->school, User::ROLE_TEACHER);

        $this->actingAs($this->ani)->get(route('live.play', $s));
        $this->actingAs($this->budi)->get(route('live.play', $s));
        auth()->logout();
        $this->post(route('guest.store'), ['code' => $s->code, 'name' => 'Tamu Satu'])->assertRedirect(route('live.play', $s));
        $guest = auth()->user();
        $this->get(route('live.play', $s))->assertOk();
        $this->get(route('student.dashboard'))->assertRedirect(route('home'));

        $teams = $this->inSchool($this->school, fn () => QuizAttempt::where('session_id', $s->id)->pluck('team')->sort()->values()->all());
        $this->assertSame([1, 1, 2], $teams);
        $st = $this->state($this->ani, $s);
        $this->assertSame(['Bintang', 'Bulan'], $st['teams']);

        // Guru lain bukan host; siswa tidak boleh mengatur jalannya kuis
        $this->actingAs($other)->postJson(route('live.action', $s), ['action' => 'start'])->assertForbidden();
        $this->actingAs($this->ani)->postJson(route('live.action', $s), ['action' => 'start'])->assertForbidden();
        $this->actingAs($other)->get(route('live.host', $s))->assertForbidden();

        // Kanal siaran: hanya host dan pemain
        $live = app(LiveQuiz::class);
        $outsider = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->inSchool($this->school, function () use ($live, $s, $outsider, $guest) {
            $this->assertTrue($live->canWatch($this->teacher, $s));
            $this->assertTrue($live->canWatch($guest, $s));
            $this->assertFalse($live->canWatch($outsider, $s));
        });

        // Keluarkan tamu: tidak bisa bergabung lagi
        $this->act($s, 'kick', ['user_id' => $guest->id])->assertOk()->assertJsonCount(2, 'host.players');
        $this->actingAs($guest)->getJson(route('live.state', $s))->assertOk()->assertJsonPath('me.kicked', true)->assertJsonPath('question', null)->assertJsonPath('leaderboard', null);
        $this->actingAs($guest)->get(route('live.play', $s))->assertRedirect(route('home'));

        // Guru tidak bisa menjadi pemain; tamu dari kuis tanpa izin tamu ditolak
        $this->actingAs($other)->get(route('live.play', $s))->assertRedirect(route('home'));
    }

    public function test_broadcast_signal_carries_no_question_data(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([LiveQuizUpdated::class]);
        $s = $this->startLive();
        $this->actingAs($this->ani)->get(route('live.play', $s));
        $this->act($s, 'start')->assertOk();

        Event::assertDispatched(LiveQuizUpdated::class, function (LiveQuizUpdated $e) use ($s) {
            $payload = json_encode($e->broadcastWith());

            return $e->broadcastOn()->name === "private-school.{$s->school_id}.live.{$s->id}"
                && ! str_contains($payload, 'Soal') && ! str_contains($payload, '"b"');
        });
    }
}
