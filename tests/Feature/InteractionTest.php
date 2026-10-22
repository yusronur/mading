<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tahap 7: komentar dan reaksi. */
class InteractionTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Board $board;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Diskusi', 'visibility' => 'school']));
        $this->post = $this->inSchool($this->school, fn () => $this->board->posts()->create(['author_id' => $this->ani->id, 'body' => '<p>Ide Ani</p>', 'status' => 'published']));
    }

    private function settings(array $extra): void
    {
        $this->inSchool($this->school, fn () => $this->board->update(['settings' => $extra + ($this->board->fresh()->settings ?? [])]));
    }

    private function props(User $as): array
    {
        return $this->actingAs($as)->get(route('boards.show', $this->board))->original->getData()['page']['props'];
    }

    public function test_comments_threads_one_level_and_counts(): void
    {
        $c = $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => '<b>Setuju</b>'])
            ->assertCreated()->json('comments.0');
        $this->assertSame('Setuju', $c['body']);

        $reply = $this->actingAs($this->ani)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'Makasih', 'parent_id' => $c['id']])
            ->json('comments.0.replies.0');
        // balasan atas balasan menempel ke induk yang sama
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'Sama-sama', 'parent_id' => $reply['id']])
            ->assertJsonCount(1, 'comments')->assertJsonCount(2, 'comments.0.replies');

        $this->assertSame(3, $this->props($this->ani)['posts'][0]['comment_count']);

        // Ani tidak boleh menghapus komentar Budi; guru boleh (balasan ikut terhapus)
        $this->actingAs($this->ani)->deleteJson(route('comments.destroy', [$this->board, $c['id']]))->assertForbidden();
        $this->actingAs($this->teacher)->deleteJson(route('comments.destroy', [$this->board, $c['id']]))->assertOk()->assertJsonCount(0, 'comments');
        $this->assertSame(0, $this->props($this->ani)['posts'][0]['comment_count']);
    }

    public function test_comments_closed_when_disabled_or_frozen(): void
    {
        $this->settings(['allow_comments' => false]);
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'hai'])->assertForbidden();
        $this->actingAs($this->teacher)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'guru tetap bisa'])->assertCreated();

        $this->settings(['allow_comments' => true]);
        $this->inSchool($this->school, fn () => $this->board->update(['is_frozen' => true]));
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'hai'])->assertForbidden();
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1])->assertForbidden();
    }

    public function test_anonymous_comment_hides_author_from_students_only(): void
    {
        $this->settings(['allow_anonymous' => true]);
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'rahasia', 'is_anonymous' => true]);

        $this->actingAs($this->ani)->getJson(route('comments.index', [$this->board, $this->post]))->assertJsonPath('comments.0.author', 'Anonim');
        $this->actingAs($this->teacher)->getJson(route('comments.index', [$this->board, $this->post]))->assertJsonPath('comments.0.author', 'Budi');
        $this->actingAs($this->budi)->getJson(route('comments.index', [$this->board, $this->post]))->assertJsonPath('comments.0.author', 'Budi');
    }

    public function test_like_star_vote_and_grade(): void
    {
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1]);
        $this->actingAs($this->teacher)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1]);
        $r = $this->props($this->budi)['posts'][0]['reaction'];
        $this->assertSame(['kind' => 'like', 'count' => 2, 'mine' => 1], $r);
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => null]);
        $this->assertSame(1, $this->props($this->budi)['posts'][0]['reaction']['count']);

        $this->settings(['reaction' => 'star']);
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 4]);
        $this->actingAs($this->teacher)->post(route('posts.react', [$this->board, $this->post]), ['value' => 5]);
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 9])->assertSessionHasErrors('value');
        $this->assertSame(4.5, $this->props($this->ani)['posts'][0]['reaction']['average']);

        $this->settings(['reaction' => 'vote']);
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => -1]);
        $this->actingAs($this->teacher)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1]);
        $this->actingAs($this->ani)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1]);
        $this->assertSame(1, $this->props($this->ani)['posts'][0]['reaction']['score']);

        // nilai: hanya guru memberi; terlihat oleh guru dan penulis saja
        $this->settings(['reaction' => 'grade']);
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 100])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('posts.react', [$this->board, $this->post]), ['value' => 87]);
        $this->assertSame(87, $this->props($this->ani)['posts'][0]['reaction']['grade']);
        $this->assertNull($this->props($this->budi)['posts'][0]['reaction']['grade']);
    }

    public function test_reacting_changes_sync_version(): void
    {
        $v = fn () => $this->actingAs($this->ani)->getJson(route('boards.sync', $this->board))->json('v');
        $before = $v();
        $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $this->post]), ['value' => 1]);
        $this->assertNotSame($before, $v());
    }

    public function test_author_is_notified_only_of_comments_by_others(): void
    {
        $count = fn (User $as, $post) => collect($this->props($as)['posts'])->firstWhere('id', $post->id);

        $this->assertSame(0, $count($this->ani, $this->post)['others_comments']);

        // Komentar sendiri pada kartu sendiri tidak dihitung sebagai "komentar baru"
        $this->actingAs($this->ani)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'Tambahan dariku'])->assertCreated();
        $this->assertSame(0, $count($this->ani, $this->post)['others_comments']);

        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'Keren!'])->assertCreated();
        $this->actingAs($this->teacher)->postJson(route('comments.store', [$this->board, $this->post]), ['body' => 'Bagus'])->assertCreated();
        $this->assertSame(2, $count($this->ani, $this->post)['others_comments']);
        $this->assertTrue($count($this->ani, $this->post)['is_mine']);

        // Pembanding naik hanya untuk penulis kartu; bagi Budi kartu Ani bukan miliknya
        $this->assertFalse($count($this->budi, $this->post)['is_mine']);
        $this->assertSame(0, $count($this->budi, $this->post)['others_comments']);
    }
}
