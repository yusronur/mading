<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Media\LinkPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tahap 17: daftar centang, sematan, rekaman suara, coretan. */
class CardContentTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT);
        $class = $this->inSchool($this->school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]));
        foreach ([$this->ani, $this->budi] as $u) {
            $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $class->id, 'user_id' => $u->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));
        }
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Proyek', 'visibility' => 'class', 'class_id' => $class->id]));
    }

    private function lastPost(): Post
    {
        return $this->inSchool($this->school, fn () => Post::latest('id')->firstOrFail());
    }

    public function test_checklist_only_card_is_saved_cleaned_and_rendered(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), [
            'checklist' => [['text' => ' Cari bahan <b>x</b> ', 'done' => true], ['text' => '   ', 'done' => false], ['text' => 'Tulis laporan']],
        ])->assertSessionHasNoErrors();

        $post = $this->lastPost();
        $this->assertEquals([['text' => 'Cari bahan x', 'done' => true], ['text' => 'Tulis laporan', 'done' => false]], $post->checklist);
        $this->actingAs($this->budi)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('posts.0.checklist.1.text', 'Tulis laporan')->where('posts.0.can_check', false));

        // terlalu banyak butir
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['checklist' => array_fill(0, 31, ['text' => 'a'])])
            ->assertSessionHasErrors('checklist');
    }

    public function test_profanity_in_checklist_is_held(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Tugas', 'checklist' => [['text' => 'Budi goblok']]]);
        $this->assertSame('pending', $this->lastPost()->status);
    }

    public function test_check_toggle_permissions_and_group_mode(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Kelompok', 'checklist' => [['text' => 'A'], ['text' => 'B']]]);
        $post = $this->lastPost();

        $this->actingAs($this->ani)->postJson(route('posts.check', [$this->board, $post, 1]), ['done' => true])
            ->assertOk()->assertJsonPath('checklist.1.done', true);
        $this->actingAs($this->budi)->postJson(route('posts.check', [$this->board, $post, 0]), ['done' => true])->assertForbidden();
        $this->actingAs($this->teacher)->postJson(route('posts.check', [$this->board, $post, 0]), ['done' => true])->assertOk();
        $this->actingAs($this->ani)->postJson(route('posts.check', [$this->board, $post, 9]), ['done' => true])->assertNotFound();

        // Mode kelompok: semua anggota boleh mencentang
        $this->inSchool($this->school, fn () => $this->board->forceFill(['settings' => array_merge($this->board->settings ?? [], ['checklist_all' => true])])->save());
        $this->actingAs($this->budi)->postJson(route('posts.check', [$this->board, $post, 0]), ['done' => false])
            ->assertOk()->assertJsonPath('checklist.0.done', false);
        $this->actingAs($this->budi)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('posts.0.can_check', true));

        // Papan beku: siswa tidak bisa lagi
        $this->inSchool($this->school, fn () => $this->board->forceFill(['is_frozen' => true])->save());
        $this->actingAs($this->budi)->postJson(route('posts.check', [$this->board, $post, 0]), ['done' => true])->assertForbidden();
    }

    public function test_embed_providers_build_safe_urls(): void
    {
        $e = fn ($u) => LinkPreview::embed($u)['embed_url'] ?? null;
        $this->assertSame('https://player.vimeo.com/video/76979871', $e('https://vimeo.com/76979871'));
        $this->assertSame('https://docs.google.com/presentation/d/1AbCdEfGhIjKlMnOp/preview', $e('https://docs.google.com/presentation/d/1AbCdEfGhIjKlMnOp/edit?usp=sharing'));
        $this->assertSame('https://docs.google.com/forms/d/e/1FAIpQLSdAbcdefghij/viewform?embedded=true', $e('https://docs.google.com/forms/d/e/1FAIpQLSdAbcdefghij/viewform'));
        $this->assertSame('https://drive.google.com/file/d/1aBcDeFgHiJkLmN/preview', $e('https://drive.google.com/file/d/1aBcDeFgHiJkLmN/view'));
        $this->assertNull($e('https://evil.example/?u=https://vimeo.com/76979871'));
        $this->assertNull($e('https://docs.google.com.evil.example/document/d/1AbCdEfGhIjKlMnOp'));

        $this->app->bind(LinkPreview::class, fn () => new class extends LinkPreview
        {
            protected function publicIp(string $host): ?string
            {
                return '93.184.216.34';
            }
        });
        Http::fake(['vimeo.com/*' => Http::response('<html><head><title>Siklus Air</title></head></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['link' => 'https://vimeo.com/76979871'])->assertSessionHasNoErrors();
        $this->actingAs($this->budi)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('posts.0.link.type', 'embed')->where('posts.0.link.provider', 'Vimeo')
                ->where('posts.0.link.embed_url', 'https://player.vimeo.com/video/76979871')->where('posts.0.link.title', 'Siklus Air'));
    }

    public function test_browser_voice_recording_is_stored_as_audio(): void
    {
        // WebM berisi audio saja; finfo membacanya sebagai video/webm
        $webm = "\x1A\x45\xDF\xA3".str_repeat("\x00", 60).'webm'.str_repeat("\x00", 200);
        $file = UploadedFile::fake()->createWithContent('rekaman-suara-1.weba', $webm);
        $res = $this->actingAs($this->ani)->post(route('attachments.store', $this->board), ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $a = $this->inSchool($this->school, fn () => Attachment::find($res->json('id')));
        $this->assertSame('audio', $a->kind);
    }

    public function test_drawing_png_is_accepted_as_image(): void
    {
        $file = UploadedFile::fake()->image('coretan-1.png', 1200, 800);
        $res = $this->actingAs($this->ani)->post(route('attachments.store', $this->board), ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('image', $res->json('kind'));
    }
}
