<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\Board;
use App\Models\Post;
use App\Models\PostGrade;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tahap 18: rubrik, rekap nilai, template, salin ke rombel, jadwal, komentar cepat. */
class TeacherToolsTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $other;

    private User $ani;

    private User $budi;

    private SchoolClass $class;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Bu Rina']);
        $this->other = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Pak Joko']);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->inSchool($this->school, function () {
            $this->class = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]);
            foreach ([$this->ani, $this->budi] as $u) {
                DB::table('class_members')->insert(['class_id' => $this->class->id, 'user_id' => $u->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->board = Board::create(['owner_id' => $this->teacher->id, 'title' => 'Poster Ekosistem', 'visibility' => 'class', 'class_id' => $this->class->id]);
        });
    }

    private function settings(array $extra): void
    {
        $this->actingAs($this->teacher)->put(route('boards.update', $this->board), $extra + [
            'title' => $this->board->title, 'visibility' => 'class', 'class_id' => $this->class->id,
            'visitor_permission' => 'write', 'reaction' => 'like', 'allow_comments' => true, 'format' => 'wall', 'sort' => 'newest',
        ])->assertSessionHasNoErrors();
        $this->board = $this->inSchool($this->school, fn () => $this->board->fresh());
    }

    private function postAs(User $u, string $title): Post
    {
        $this->actingAs($u)->post(route('posts.store', $this->board), ['title' => $title])->assertSessionHasNoErrors();

        return $this->inSchool($this->school, fn () => Post::where('title', $title)->firstOrFail());
    }

    public function test_rubric_grading_visibility_and_notification(): void
    {
        $this->settings(['rubric' => [['name' => 'Isi', 'max' => 40], ['name' => 'Kerapian', 'max' => 10], ['name' => '', 'max' => 5]]]);
        $this->assertEquals([['name' => 'Isi', 'max' => 40], ['name' => 'Kerapian', 'max' => 10]], $this->board->rubric());

        $post = $this->postAs($this->ani, 'Poster Ani');
        $url = route('posts.grade', [$this->board, $post]);

        $this->actingAs($this->budi)->putJson($url, ['scores' => [40, 10]])->assertForbidden();
        $this->actingAs($this->teacher)->putJson($url, ['scores' => [41, 10]])->assertStatus(422);
        $this->actingAs($this->teacher)->putJson($url, ['scores' => [30]])->assertStatus(422);
        $this->actingAs($this->teacher)->putJson($url, ['scores' => [30, 7.5], 'feedback' => 'Bagus, tambah gambar.'])
            ->assertOk()->assertJsonPath('grade.grade', 75)->assertJsonPath('grade.total', 37.5);

        // Penulis melihat nilai + umpan balik; siswa lain tidak
        $this->actingAs($this->ani)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('posts.0.grade.grade', 75)->where('posts.0.grade.feedback', 'Bagus, tambah gambar.'));
        $this->actingAs($this->budi)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('posts.0.grade', null));

        // Notifikasi sekali saja walau dinilai ulang
        $this->actingAs($this->teacher)->putJson($url, ['scores' => [40, 10]])->assertOk()->assertJsonPath('grade.grade', 100);
        $this->assertSame(1, $this->inSchool($this->school, fn () => AppNotification::where('user_id', $this->ani->id)->where('kind', 'grade_ready')->count()));

        // Kartu guru tidak dinilai
        $mine = $this->postAs($this->teacher, 'Contoh guru');
        $this->actingAs($this->teacher)->putJson(route('posts.grade', [$this->board, $mine]), ['scores' => [1, 1]])->assertStatus(422);
    }

    public function test_recap_combines_quiz_and_board_grades_and_exports(): void
    {
        $this->settings(['rubric' => [['name' => 'Isi', 'max' => 100]]]);
        $post = $this->postAs($this->ani, 'Tugas Ani');
        $this->actingAs($this->teacher)->putJson(route('posts.grade', [$this->board, $post]), ['scores' => [80]])->assertOk();

        $this->inSchool($this->school, function () {
            $quiz = Quiz::create(['owner_id' => $this->teacher->id, 'title' => 'Ulangan 1', 'settings' => []]);
            $mk = fn (User $host, string $title) => QuizSession::create(['quiz_id' => $quiz->id, 'host_id' => $host->id, 'class_id' => $this->class->id, 'code' => strtoupper(substr(md5($title), 0, 6)),
                'title' => $title, 'settings' => ['scoring' => 'best', 'kkm' => 75], 'questions' => [], 'closes_at' => now()->addDay()]);
            $s = $mk($this->teacher, 'Ulangan 1');
            $o = $mk($this->other, 'Kuis Pak Joko');
            $att = fn ($s, $u, $no, $score) => QuizAttempt::create(['session_id' => $s->id, 'user_id' => $u->id, 'attempt_no' => $no, 'seed' => 1, 'question_order' => [],
                'started_at' => now(), 'submitted_at' => now(), 'score' => $score, 'max_score' => 10]);
            $att($s, $this->ani, 1, 6);
            $att($s, $this->ani, 2, 9);   // terbaik dipakai
            $att($s, $this->budi, 1, 5);
            $att($o, $this->ani, 1, 10);
        });

        $props = $this->actingAs($this->teacher)->get(route('recap.index', ['kelas' => $this->class->id]))->original->getData()['page']['props'];
        $r = $props['recap'];
        $this->assertCount(2, $r['columns']); // kuis Pak Joko tidak ikut
        $ani = collect($r['rows'])->firstWhere('name', 'Ani');
        $budi = collect($r['rows'])->firstWhere('name', 'Budi');
        $q = collect($r['columns'])->firstWhere('kind', 'quiz')['key'];
        $b = collect($r['columns'])->firstWhere('kind', 'board')['key'];
        $this->assertSame(90, $ani['cells'][$q]);
        $this->assertSame(80, $ani['cells'][$b]);
        $this->assertEquals(85, $ani['average']);
        $this->assertNull($budi['cells'][$b]);
        $this->assertEquals(70, $r['column_average'][$q]);

        $res = $this->actingAs($this->teacher)->get(route('recap.export', ['kelas' => $this->class->id]));
        $res->assertOk();
        $this->assertStringContainsString('rekap-nilai-7a', $res->headers->get('content-disposition'));

        $this->actingAs($this->ani)->get(route('recap.index'))->assertForbidden();
    }

    public function test_templates_are_private_unless_shared_and_copy_teacher_cards_only(): void
    {
        $this->settings(['rubric' => [['name' => 'Isi', 'max' => 10]]]);
        $this->postAs($this->teacher, 'Contoh jawaban');
        $this->postAs($this->ani, 'Jawaban Ani');

        $this->actingAs($this->teacher)->post(route('boards.save-template', $this->board), ['title' => 'Template Poster', 'with_posts' => true])->assertSessionHasNoErrors();
        $tpl = $this->inSchool($this->school, fn () => Board::where('is_template', true)->firstOrFail());
        $this->assertSame('private', $tpl->visibility);
        $this->assertSame(['Contoh jawaban'], $this->inSchool($this->school, fn () => $tpl->posts()->pluck('title')->all()));

        // Tidak muncul di daftar papan, muncul di daftar template
        $this->actingAs($this->teacher)->get(route('teacher.dashboard'))
            ->assertInertia(fn ($p) => $p->where('boards', fn ($b) => collect($b)->where('is_template', true)->isEmpty() && collect($b)->pluck('title')->doesntContain('Template Poster'))->where('templates.0.title', 'Template Poster'));
        $this->actingAs($this->other)->get(route('teacher.dashboard'))->assertInertia(fn ($p) => $p->has('templates', 0));
        $this->actingAs($this->other)->post(route('templates.use', $tpl), ['title' => 'X', 'visibility' => 'private'])->assertNotFound();

        $this->actingAs($this->teacher)->post(route('templates.share', $tpl), ['shared' => true]);
        $this->actingAs($this->other)->post(route('templates.use', $tpl), ['title' => 'Poster 7A', 'visibility' => 'class', 'class_id' => $this->class->id])->assertRedirect();
        $new = $this->inSchool($this->school, fn () => Board::where('title', 'Poster 7A')->firstOrFail());
        $this->assertSame($this->other->id, $new->owner_id);
        $this->assertFalse($new->is_template);
        $this->assertEquals([['name' => 'Isi', 'max' => 10]], $new->rubric());
        $this->assertSame(1, $this->inSchool($this->school, fn () => $new->posts()->count()));
        $this->actingAs($this->ani)->post(route('boards.save-template', $this->board), ['title' => 'x'])->assertForbidden();
    }

    public function test_copy_to_classes_for_new_year(): void
    {
        $this->postAs($this->ani, 'Jawaban Ani');
        [$b7, $b8] = $this->inSchool($this->school, fn () => [
            SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7B', 'grade' => 7]),
            SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7C', 'grade' => 7]),
        ]);
        $this->actingAs($this->teacher)->post(route('boards.copy-classes', $this->board), ['class_ids' => [$b7->id, $b8->id], 'posts' => 'staff'])->assertSessionHasNoErrors();
        $copies = $this->inSchool($this->school, fn () => Board::where('title', 'Poster Ekosistem')->where('id', '!=', $this->board->id)->get());
        $this->assertEqualsCanonicalizing([$b7->id, $b8->id], $copies->pluck('class_id')->all());
        $this->assertSame(0, $this->inSchool($this->school, fn () => Post::whereIn('board_id', $copies->pluck('id'))->count())); // kartu siswa tidak ikut
        $this->actingAs($this->teacher)->post(route('boards.copy-classes', $this->board), ['class_ids' => [], 'posts' => 'none'])->assertSessionHasErrors('class_ids');
    }

    public function test_schedule_hides_before_open_and_locks_after_close(): void
    {
        $this->postAs($this->teacher, 'Petunjuk');
        $fmt = fn ($t) => $t->timezone(config('app.timezone'))->format('Y-m-d\TH:i');
        $this->settings(['opens_at' => $fmt(now()->addHour()), 'closes_at' => $fmt(now()->addHours(3))]);
        $this->assertSame('before', $this->board->scheduleState());

        $this->actingAs($this->ani)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('board.schedule', 'before')->has('posts', 0)->where('can.post', false));
        $this->actingAs($this->teacher)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->has('posts', 1));
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Curi start'])->assertForbidden();

        $this->travel(2)->hours();
        $this->assertSame('open', $this->inSchool($this->school, fn () => $this->board->fresh()->scheduleState()));
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Tepat waktu'])->assertSessionHasNoErrors();

        $this->travel(2)->hours();
        $this->actingAs($this->ani)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('board.schedule', 'after')->has('posts', 2)->where('can.post', false));
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Telat'])->assertForbidden();

        // waktu tutup sebelum buka ditolak
        $this->actingAs($this->teacher)->put(route('boards.update', $this->board), [
            'title' => 'x', 'visibility' => 'class', 'class_id' => $this->class->id, 'visitor_permission' => 'write', 'reaction' => 'like', 'format' => 'wall', 'sort' => 'newest',
            'opens_at' => $fmt(now()->addDay()), 'closes_at' => $fmt(now()),
        ])->assertSessionHasErrors('closes_at');
    }

    public function test_quick_comments_default_and_save(): void
    {
        $this->actingAs($this->teacher)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('quick_comments.0', 'Bagus sekali! 👍'));
        $this->actingAs($this->teacher)->putJson(route('quick-comments.save'), ['items' => ['Mantap', ' Mantap ', '<b>Rapi</b>', '']])
            ->assertOk()->assertJsonPath('items', ['Mantap', 'Rapi']);
        $this->actingAs($this->teacher)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('quick_comments', ['Mantap', 'Rapi']));
        $this->actingAs($this->ani)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('quick_comments', null));
        $this->actingAs($this->ani)->putJson(route('quick-comments.save'), ['items' => ['x']])->assertForbidden();
    }
}
