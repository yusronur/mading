<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Tests\TestCase;

/** Tahap 8: moderasi, filter kata, laporan, batas kartu, tautan rahasia. */
class ModerationTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Refleksi', 'visibility' => 'school']));
    }

    private function set(array $settings, array $attrs = []): void
    {
        $this->inSchool($this->school, fn () => $this->board->update($attrs + ['settings' => $settings + ($this->board->fresh()->settings ?? [])]));
    }

    private function titles(User $as): array
    {
        return array_column($this->actingAs($as)->get(route('boards.show', $this->board))->original->getData()['page']['props']['posts'], 'status', 'title');
    }

    public function test_bad_words_hold_post_until_teacher_approves(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Budi goblok'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ditahan'));
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Ide bagus']);

        $this->assertSame(['Ide bagus' => 'published'], $this->titles($this->budi), 'siswa lain tidak melihat kartu yang ditahan');
        $this->assertSame('pending', $this->titles($this->ani)['Budi goblok'], 'penulis melihat kartunya sendiri yang ditahan');
        $this->assertSame('pending', $this->titles($this->teacher)['Budi goblok']);

        $post = $this->inSchool($this->school, fn () => Post::where('title', 'Budi goblok')->first());
        $this->actingAs($this->budi)->post(route('moderation.post', [$this->board, $post]), ['action' => 'approve'])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('moderation.post', [$this->board, $post]), ['action' => 'approve']);
        $this->assertSame('published', $this->titles($this->budi)['Budi goblok']);

        $this->actingAs($this->teacher)->post(route('moderation.post', [$this->board, $post]), ['action' => 'hide']);
        $this->assertArrayNotHasKey('Budi goblok', $this->titles($this->budi));
        $this->actingAs($this->teacher)->post(route('moderation.post', [$this->board, $post]), ['action' => 'reject']);
        $this->assertArrayNotHasKey('Budi goblok', $this->titles($this->teacher));
    }

    public function test_require_approval_for_posts_and_comments(): void
    {
        $this->set(['require_approval' => true]);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Menunggu']);
        $this->actingAs($this->teacher)->post(route('posts.store', $this->board), ['title' => 'Dari guru']);
        $this->assertSame(['Dari guru' => 'published'], $this->titles($this->budi));

        $post = $this->inSchool($this->school, fn () => Post::where('title', 'Dari guru')->first());
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $post]), ['body' => 'mantap'])->assertJsonPath('held', true);
        $this->actingAs($this->ani)->getJson(route('comments.index', [$this->board, $post]))->assertJsonCount(0, 'comments');

        $queue = $this->actingAs($this->teacher)->getJson(route('moderation.queue', $this->board))->assertJsonCount(1, 'comments');
        $this->actingAs($this->teacher)->postJson(route('moderation.comment', [$this->board, $queue->json('comments.0.id')]), ['action' => 'approve'])->assertOk();
        $this->actingAs($this->ani)->getJson(route('comments.index', [$this->board, $post]))->assertJsonCount(1, 'comments');
        $this->assertSame(1, $this->inSchool($this->school, fn () => $post->fresh()->comment_count));
    }

    public function test_reports_auto_hold_after_three(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Kontroversial']);
        $post = $this->inSchool($this->school, fn () => Post::firstOrFail());

        $this->actingAs($this->ani)->post(route('posts.report', [$this->board, $post]))->assertStatus(422);
        $reporters = [$this->budi, $this->makeUser($this->school, User::ROLE_STUDENT), $this->makeUser($this->school, User::ROLE_STUDENT)];
        foreach ($reporters as $i => $r) {
            $this->actingAs($r)->post(route('posts.report', [$this->board, $post]), ['reason' => 'mengejek']);
            $this->actingAs($r)->post(route('posts.report', [$this->board, $post])); // laporan ganda tidak dihitung
        }

        $fresh = $this->inSchool($this->school, fn () => $post->fresh());
        $this->assertSame(3, $fresh->report_count);
        $this->assertSame('pending', $fresh->status);
        $this->actingAs($this->teacher)->getJson(route('moderation.queue', $this->board))->assertJsonCount(3, "reports.{$post->id}");
    }

    public function test_max_posts_per_student(): void
    {
        $this->set(['max_posts' => 2]);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => '1']);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => '2']);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => '3'])->assertSessionHasErrors('body');
        $this->actingAs($this->teacher)->post(route('posts.store', $this->board), ['title' => 'guru'])->assertSessionHasNoErrors();
    }

    public function test_student_moderators_when_permission_is_moderate(): void
    {
        $this->set(['require_approval' => true], ['visitor_permission' => 'moderate']);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'oleh Ani']);
        $this->assertSame('published', $this->titles($this->ani)['oleh Ani'], 'moderator siswa tidak ditahan');
    }

    public function test_secret_link_board(): void
    {
        $this->inSchool($this->school, fn () => $this->board->update(['visibility' => 'link', 'secret_token' => str_repeat('a', 32)]));

        $this->actingAs($this->ani)->get(route('boards.show', $this->board))->assertForbidden();
        $this->actingAs($this->ani)->get('/p/'.str_repeat('b', 32))->assertNotFound();
        $this->actingAs($this->ani)->get('/p/'.str_repeat('a', 32))->assertRedirect(route('boards.show', $this->board));
        $this->actingAs($this->ani)->get(route('boards.show', $this->board))->assertOk();
        $this->actingAs($this->ani)->get('/siswa')->assertInertia(fn ($p) => $p->has('boards', 1));

        // kode papan juga berfungsi sebagai kunci
        $this->actingAs($this->budi)->post('/papan/gabung', ['code' => $this->board->code])->assertRedirect(route('boards.show', $this->board));
    }
}
