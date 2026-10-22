<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Irisan tipis Fase 0: guru membuat papan, siswa membuka dan menempel kartu. */
class BoardTest extends TestCase
{
    private function classWith(object $school, string $name, User ...$students): SchoolClass
    {
        return $this->inSchool($school, function () use ($name, $students) {
            $class = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => $name, 'grade' => 7]);
            foreach ($students as $s) {
                DB::table('class_members')->insert(['class_id' => $class->id, 'user_id' => $s->id, 'school_id' => $s->school_id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $class;
        });
    }

    public function test_thin_slice_teacher_creates_board_and_student_posts(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $class = $this->classWith($school, '7A', $student);

        $this->actingAs($teacher)->post('/papan', [
            'title' => 'Refleksi IPA', 'visibility' => 'class', 'class_id' => $class->id,
        ])->assertRedirect();

        $board = $this->inSchool($school, fn () => Board::firstOrFail());
        $this->assertSame(6, strlen($board->code));

        // Siswa melihat papan di beranda dan bisa bergabung dengan kode
        $this->actingAs($student)->get('/siswa')->assertInertia(fn ($p) => $p->has('boards', 1)->where('boards.0.title', 'Refleksi IPA'));
        $this->actingAs($student)->post('/papan/gabung', ['code' => strtolower($board->code)])->assertRedirect(route('boards.show', $board));

        $this->actingAs($student)->post(route('posts.store', $board), ['title' => 'Ideku', 'body' => '<b>Fotosintesis</b> butuh cahaya'])
            ->assertSessionHas('success');

        $post = $this->inSchool($school, fn () => Post::firstOrFail());
        $this->assertSame('<p><strong>Fotosintesis</strong> butuh cahaya</p>', $post->body, 'HTML dibersihkan, format sederhana dipertahankan');

        $this->actingAs($student)->get(route('boards.show', $board))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Boards/Show')->has('posts', 1)->where('can.post', true)->where('can.manage', false));
    }

    public function test_student_of_other_class_cannot_open_or_post(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        [$inside, $outside] = [$this->makeUser($school, User::ROLE_STUDENT), $this->makeUser($school, User::ROLE_STUDENT)];
        $class = $this->classWith($school, '7A', $inside);
        $this->classWith($school, '7B', $outside);

        $board = $this->inSchool($school, fn () => Board::create(['owner_id' => $teacher->id, 'title' => 'Khusus 7A', 'visibility' => 'class', 'class_id' => $class->id]));

        $this->actingAs($outside)->get(route('boards.show', $board))->assertForbidden();
        $this->actingAs($outside)->post(route('posts.store', $board), ['body' => 'iseng'])->assertForbidden();
        $this->actingAs($outside)->post('/papan/gabung', ['code' => $board->code])->assertSessionHas('error');
        $this->actingAs($outside)->get('/siswa')->assertInertia(fn ($p) => $p->has('boards', 0));
    }

    public function test_private_board_hidden_from_other_teachers_and_students_cannot_create_boards(): void
    {
        $school = $this->makeSchool();
        [$owner, $other] = [$this->makeUser($school, User::ROLE_TEACHER), $this->makeUser($school, User::ROLE_TEACHER)];
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $board = $this->inSchool($school, fn () => Board::create(['owner_id' => $owner->id, 'title' => 'Draf', 'visibility' => 'private']));

        $this->actingAs($other)->get(route('boards.show', $board))->assertForbidden();
        $this->actingAs($owner)->get(route('boards.show', $board))->assertOk();
        $this->actingAs($student)->post('/papan', ['title' => 'Papanku', 'visibility' => 'school'])->assertForbidden();
    }

    public function test_student_deletes_only_own_post_and_teacher_moderates(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        [$s1, $s2] = [$this->makeUser($school, User::ROLE_STUDENT), $this->makeUser($school, User::ROLE_STUDENT)];
        $board = $this->inSchool($school, fn () => Board::create(['owner_id' => $teacher->id, 'title' => 'Umum', 'visibility' => 'school']));
        [$p1, $p2] = $this->inSchool($school, fn () => [
            $board->posts()->create(['author_id' => $s1->id, 'body' => 'satu']),
            $board->posts()->create(['author_id' => $s2->id, 'body' => 'dua']),
        ]);

        $this->actingAs($s1)->delete(route('posts.destroy', [$board, $p2]))->assertForbidden();
        $this->actingAs($s1)->delete(route('posts.destroy', [$board, $p1]))->assertSessionHas('success');
        $this->actingAs($teacher)->delete(route('posts.destroy', [$board, $p2]))->assertSessionHas('success');

        $this->assertSame(0, $this->inSchool($school, fn () => Post::count()));
    }

    public function test_anonymous_author_is_not_sent_to_other_students(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        [$author, $reader] = [$this->makeUser($school, User::ROLE_STUDENT, ['name' => 'Rahasia Banget']), $this->makeUser($school, User::ROLE_STUDENT)];
        $board = $this->inSchool($school, fn () => Board::create(['owner_id' => $teacher->id, 'title' => 'Curhat', 'visibility' => 'school']));
        $this->inSchool($school, fn () => $board->posts()->create(['author_id' => $author->id, 'body' => 'aku malu', 'is_anonymous' => true]));

        $response = $this->actingAs($reader)->get(route('boards.show', $board));
        $response->assertInertia(fn ($p) => $p->where('posts.0.author', 'Anonim'));
        $this->assertStringNotContainsString('Rahasia Banget', $response->getContent());

        $this->actingAs($teacher)->get(route('boards.show', $board))
            ->assertInertia(fn ($p) => $p->where('posts.0.author', 'Rahasia Banget'));
    }

    public function test_board_theme_lock_overrides_student_preference(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        [$neon, $pastel] = $this->inSchool($school, fn () => [
            Theme::where('key', 'neon')->first(),
            Theme::where('key', 'pastel')->first(),
        ]);

        $this->actingAs($student)->put('/profil/tampilan', ['theme_id' => $neon->id, 'color_mode' => 'dark', 'reduce_motion' => true, 'font_scale' => 115])
            ->assertSessionHas('success');
        $this->actingAs($student)->get('/siswa')->assertInertia(fn ($p) => $p
            ->where('theme.key', 'neon')->where('theme.mode', 'dark')->where('theme.font_scale', 115)->where('theme.reduce_motion', true));

        $locked = $this->inSchool($school, fn () => Board::create([
            'owner_id' => $teacher->id, 'title' => 'Pastel', 'visibility' => 'school', 'theme_id' => $pastel->id, 'theme_locked' => true,
        ]));
        $this->actingAs($student)->get(route('boards.show', $locked))->assertInertia(fn ($p) => $p->where('theme.key', 'pastel')->where('theme.locked', true));
    }

    private function ownedBoard(object $school, User $owner, array $attrs = []): Board
    {
        return $this->inSchool($school, fn () => Board::create($attrs + ['owner_id' => $owner->id, 'title' => 'Papanku', 'visibility' => 'school']));
    }

    public function test_owner_updates_board_settings_and_students_cannot(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $board = $this->ownedBoard($school, $teacher);
        $theme = $this->inSchool($school, fn () => Theme::orderBy('sort')->first());

        $payload = [
            'title' => '<b>Judul Baru</b>', 'description' => 'Tulis refleksimu', 'visibility' => 'school', 'class_id' => null,
            'theme_id' => $theme->id, 'theme_locked' => true, 'visitor_permission' => 'read',
            'is_frozen' => false, 'allow_anonymous' => true, 'format' => 'grid', 'sort' => 'oldest', 'reaction' => 'star', 'allow_comments' => true,
        ];

        $this->actingAs($student)->put(route('boards.update', $board), $payload)->assertForbidden();
        $this->actingAs($teacher)->put(route('boards.update', $board), $payload)->assertSessionHas('success');

        $b = $this->inSchool($school, fn () => $board->fresh());
        $this->assertSame('Judul Baru', $b->title);
        $this->assertTrue($b->theme_locked);
        $this->assertSame('read', $b->visitor_permission);
        $this->assertTrue($b->settings['allow_anonymous']);

        // siswa hanya melihat, tidak bisa menempel; data pengaturan tidak bocor ke siswa
        $this->actingAs($student)->get(route('boards.show', $board))
            ->assertInertia(fn ($p) => $p->where('can.post', false)->where('settings', null)->where('options', null));
        $this->actingAs($teacher)->get(route('boards.show', $board))
            ->assertInertia(fn ($p) => $p->where('settings.title', 'Judul Baru')->where('settings.sort', 'oldest')->where('board.format', 'grid')->has('options.themes'));
    }

    public function test_class_visibility_requires_class_and_other_visibility_clears_it(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $class = $this->classWith($school, '7A');
        $board = $this->ownedBoard($school, $teacher, ['visibility' => 'class', 'class_id' => $class->id]);
        $base = ['title' => 'X', 'visitor_permission' => 'write', 'format' => 'wall', 'sort' => 'newest', 'reaction' => 'like'];

        $this->actingAs($teacher)->put(route('boards.update', $board), $base + ['visibility' => 'class'])
            ->assertSessionHasErrors('class_id');

        $this->actingAs($teacher)->put(route('boards.update', $board), $base + ['visibility' => 'private', 'class_id' => $class->id])->assertSessionHasNoErrors();
        $this->assertNull($this->inSchool($school, fn () => $board->fresh()->class_id));
    }

    public function test_anonymous_flag_is_ignored_unless_board_allows_it(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $board = $this->ownedBoard($school, $teacher);

        $this->actingAs($student)->post(route('posts.store', $board), ['body' => 'a', 'is_anonymous' => true]);
        $this->assertFalse($this->inSchool($school, fn () => Post::firstOrFail()->is_anonymous));

        $this->inSchool($school, fn () => $board->update(['settings' => ['allow_anonymous' => true]]));
        $this->actingAs($student)->post(route('posts.store', $board), ['body' => 'b', 'is_anonymous' => true]);
        $this->assertTrue($this->inSchool($school, fn () => Post::where('body', '<p>b</p>')->firstOrFail()->is_anonymous));
    }

    public function test_regenerate_code_and_delete_board(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $other = $this->makeUser($school, User::ROLE_TEACHER);
        $board = $this->ownedBoard($school, $teacher);
        $old = $board->code;

        $this->actingAs($other)->post(route('boards.regenerate-code', $board))->assertForbidden();
        $this->actingAs($other)->delete(route('boards.destroy', $board))->assertForbidden();

        $this->actingAs($teacher)->post(route('boards.regenerate-code', $board))->assertSessionHas('success');
        $this->assertNotSame($old, $this->inSchool($school, fn () => $board->fresh()->code));

        $this->actingAs($teacher)->delete(route('boards.destroy', $board))->assertRedirect(route('home'));
        $this->assertNull($this->inSchool($school, fn () => Board::find($board->id)));
    }

    public function test_sync_version_changes_when_cards_change_and_respects_access(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $outsider = $this->makeUser($school, User::ROLE_STUDENT);
        $class = $this->classWith($school, '7A', $student);
        $board = $this->ownedBoard($school, $teacher, ['visibility' => 'class', 'class_id' => $class->id]);

        $v = fn () => $this->actingAs($student)->getJson(route('boards.sync', $board))->assertOk()->json('v');

        $v0 = $v();
        $this->assertSame($v0, $v(), 'tanpa perubahan, penanda sama');

        $this->actingAs($teacher)->post(route('posts.store', $board), ['body' => 'halo']);
        $v1 = $v();
        $this->assertNotSame($v0, $v1);

        $post = $this->inSchool($school, fn () => Post::firstOrFail());
        $this->actingAs($teacher)->delete(route('posts.destroy', [$board, $post]));
        $this->assertNotSame($v1, $v());

        $this->actingAs($outsider)->getJson(route('boards.sync', $board))->assertForbidden();
    }

    public function test_show_respects_new_post_position(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $board = $this->ownedBoard($school, $teacher, ['settings' => ['new_post_position' => 'bottom']]);
        $this->inSchool($school, function () use ($board, $teacher) {
            $board->posts()->create(['author_id' => $teacher->id, 'body' => 'pertama', 'status' => 'published', 'created_at' => now()->subMinute()]);
            $board->posts()->create(['author_id' => $teacher->id, 'body' => 'kedua', 'status' => 'published', 'created_at' => now()]);
        });

        $this->actingAs($teacher)->get(route('boards.show', $board))
            ->assertInertia(fn ($p) => $p->where('posts.0.body', 'pertama')->where('posts.1.body', 'kedua'));
    }

    public function test_switching_format_keeps_posts_and_shelf_gets_a_column(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $board = $this->ownedBoard($school, $teacher);
        $this->inSchool($school, fn () => $board->posts()->create(['author_id' => $teacher->id, 'body' => 'lama', 'status' => 'published']));

        $this->actingAs($teacher)->put(route('boards.update', $board), ['quick' => 1, 'format' => 'shelf'])->assertRedirect();

        $this->actingAs($teacher)->get(route('boards.show', $board))
            ->assertInertia(fn ($p) => $p->where('board.format', 'shelf')->has('sections', 1)->has('posts', 1)
                ->where('posts.0.section_id', fn ($id) => $id !== null));

        foreach (['stream', 'grid', 'wall'] as $f) {
            $this->actingAs($teacher)->put(route('boards.update', $board), ['quick' => 1, 'format' => $f]);
            $this->actingAs($teacher)->get(route('boards.show', $board))->assertInertia(fn ($p) => $p->where('board.format', $f)->has('posts', 1));
        }
    }

    public function test_manual_sort_move_between_columns_and_pin(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $student = $this->makeUser($school, User::ROLE_STUDENT);
        $board = $this->ownedBoard($school, $teacher, ['format' => 'shelf']);

        $this->actingAs($teacher)->post(route('sections.store', $board), ['title' => 'Ide']);
        $this->actingAs($teacher)->post(route('sections.store', $board), ['title' => 'Selesai']);
        [$ide, $done] = $this->inSchool($school, fn () => $board->sections()->get()->all());

        foreach (['A', 'B', 'C'] as $t) {
            $this->actingAs($student)->post(route('posts.store', $board), ['title' => $t, 'section_id' => $ide->id]);
        }
        $this->actingAs($teacher)->put(route('boards.update', $board), ['quick' => 1, 'sort' => 'manual']);

        $order = fn () => $this->actingAs($teacher)->get(route('boards.show', $board))->original->getData()['page']['props']['posts'];
        $this->assertSame(['C', 'B', 'A'], array_column($order(), 'title'), 'manual awal = urutan tampil (terbaru di atas)');

        $byTitle = fn ($t) => collect($order())->firstWhere('title', $t);
        // pindah A ke antara C dan B
        [$a, $c, $b] = [$byTitle('A')['id'], $byTitle('C')['id'], $byTitle('B')['id']];
        $this->actingAs($teacher)->post(route('posts.move', [$board, $a]), ['section_id' => $ide->id, 'after_id' => $c, 'before_id' => $b]);
        $this->assertSame(['C', 'A', 'B'], array_column($order(), 'title'));

        // pindah B ke kolom Selesai
        $b = $byTitle('B')['id'];
        $this->actingAs($teacher)->post(route('posts.move', [$board, $b]), ['section_id' => $done->id]);
        $this->assertSame($done->id, $byTitle('B')['section_id']);

        // siswa tidak boleh menggeser atau menyematkan
        $c = $byTitle('C')['id'];
        $this->actingAs($student)->post(route('posts.move', [$board, $c]), ['section_id' => $done->id])->assertForbidden();
        $this->actingAs($student)->post(route('posts.pin', [$board, $c]))->assertForbidden();

        // sematkan B: naik ke paling atas
        $b = $byTitle('B')['id'];
        $this->actingAs($teacher)->post(route('posts.pin', [$board, $b]));
        $this->assertSame('B', $order()[0]['title']);
        $this->assertTrue($order()[0]['is_pinned']);

        // hapus kolom Ide: kartunya pindah, tidak hilang
        $this->actingAs($teacher)->delete(route('sections.destroy', [$board, $ide->id]));
        $this->assertCount(3, $order());
        $this->assertSame([$done->id], array_values(array_unique(array_column($order(), 'section_id'))));
    }

    public function test_section_of_another_board_is_rejected(): void
    {
        $school = $this->makeSchool();
        $teacher = $this->makeUser($school, User::ROLE_TEACHER);
        $a = $this->ownedBoard($school, $teacher, ['format' => 'shelf']);
        $b = $this->ownedBoard($school, $teacher, ['format' => 'shelf']);
        $this->actingAs($teacher)->post(route('sections.store', $b), ['title' => 'Milik B']);
        $sectionB = $this->inSchool($school, fn () => $b->sections()->first());

        $this->actingAs($teacher)->post(route('posts.store', $a), ['body' => 'x', 'section_id' => $sectionB->id])->assertSessionHasErrors('section_id');
        $this->actingAs($teacher)->put(route('sections.update', [$a, $sectionB->id]), ['title' => 'Bajak'])->assertNotFound();
    }
}
