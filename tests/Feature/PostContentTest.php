<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Modules\Media\LinkPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tahap 6: teks berformat, lampiran, tautan, ubah kartu. */
class PostContentTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $student;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->student = $this->makeUser($this->school, User::ROLE_STUDENT);
        $class = $this->inSchool($this->school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]));
        $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $class->id, 'user_id' => $this->student->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Galeri', 'visibility' => 'class', 'class_id' => $class->id]));
    }

    private function upload(User $as, UploadedFile $file)
    {
        return $this->actingAs($as)->post(route('attachments.store', $this->board), ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_image_is_converted_to_webp_with_thumbnail_and_attached(): void
    {
        $res = $this->upload($this->student, UploadedFile::fake()->image('foto.jpg', 3000, 2000))->assertCreated();
        $id = $res->json('id');

        $a = $this->inSchool($this->school, fn () => Attachment::find($id));
        $this->assertSame('image/webp', $a->mime);
        $this->assertSame(1600, $a->width);
        Storage::disk('local')->assertExists([$a->path, $a->thumb_path]);

        $this->actingAs($this->student)->post(route('posts.store', $this->board), [
            'title' => 'Fotoku', 'body' => '<p><b>Lihat</b> ini<script>x</script></p>', 'color' => 'blue', 'attachment_ids' => [$id],
        ])->assertSessionHasNoErrors();

        $post = $this->inSchool($this->school, fn () => Post::firstOrFail());
        $this->assertSame('<p><strong>Lihat</strong> ini</p>', $post->body);
        $this->assertSame('blue', $post->color);

        $this->actingAs($this->teacher)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('posts.0.attachments.0.kind', 'image')->where('posts.0.color', 'blue'));

        $this->actingAs($this->teacher)->get(route('attachments.show', [$id, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_outsider_cannot_view_attachment_and_cannot_steal_others_upload(): void
    {
        $id = $this->upload($this->student, UploadedFile::fake()->create('tugas.pdf', 50, 'application/pdf'))->json('id');
        $outsider = $this->makeUser($this->school, User::ROLE_STUDENT);
        $friend = $this->makeUser($this->school, User::ROLE_STUDENT);

        // belum menempel: hanya pemilik
        $this->actingAs($outsider)->get(route('attachments.show', $id))->assertForbidden();

        // siswa lain tidak bisa memasang lampiran milik orang lain
        $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $this->board->class_id, 'user_id' => $friend->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->actingAs($friend)->post(route('posts.store', $this->board), ['title' => 'curi', 'attachment_ids' => [$id]]);
        $this->assertNull($this->inSchool($this->school, fn () => Attachment::find($id)->attachable_id));

        $this->actingAs($this->student)->post(route('posts.store', $this->board), ['title' => 'Tugas', 'attachment_ids' => [$id]]);
        $this->actingAs($friend)->get(route('attachments.show', $id))->assertOk();
        $this->actingAs($outsider)->get(route('attachments.show', $id))->assertForbidden(); // bukan anggota rombel
    }

    public function test_rejects_unsupported_type_too_large_and_full_quota(): void
    {
        $this->upload($this->student, UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tidak didukung'));
        $this->upload($this->student, UploadedFile::fake()->createWithContent('x.html', '<html><body>hai</body></html>'))->assertStatus(422);

        $this->inSchool($this->school, fn () => SchoolSetting::first()->update(['settings' => ['max_upload_mb' => 1, 'storage_quota_mb' => 1]]));
        $this->upload($this->student, UploadedFile::fake()->create('besar.pdf', 2048, 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'terlalu besar'));

        $this->upload($this->student, UploadedFile::fake()->create('a.pdf', 700, 'application/pdf'))->assertCreated();
        $this->upload($this->student, UploadedFile::fake()->create('b.pdf', 700, 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Kuota'));
    }

    public function test_author_edits_own_post_others_cannot_and_delete_purges_files(): void
    {
        $id = $this->upload($this->student, UploadedFile::fake()->image('a.png', 100, 100))->json('id');
        $this->actingAs($this->student)->post(route('posts.store', $this->board), ['title' => 'Awal', 'attachment_ids' => [$id]]);
        $post = $this->inSchool($this->school, fn () => Post::firstOrFail());
        $path = $this->inSchool($this->school, fn () => Attachment::find($id)->path);

        $friend = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $this->board->class_id, 'user_id' => $friend->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->actingAs($friend)->put(route('posts.update', [$this->board, $post]), ['title' => 'Diretas'])->assertForbidden();

        $this->actingAs($this->student)->put(route('posts.update', [$this->board, $post]), ['title' => 'Revisi', 'attachment_ids' => [$id]])->assertSessionHasNoErrors();
        $fresh = $this->inSchool($this->school, fn () => $post->fresh());
        $this->assertSame('Revisi', $fresh->title);
        $this->assertNotNull($fresh->edited_at);

        // guru boleh mengubah kartu siswa; membuang lampiran menghapus berkasnya
        $this->actingAs($this->teacher)->put(route('posts.update', [$this->board, $post]), ['title' => 'Dirapikan guru', 'attachment_ids' => []])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($this->student)->delete(route('posts.destroy', [$this->board, $post]))->assertSessionHas('success');
    }

    public function test_empty_card_is_rejected(): void
    {
        $this->actingAs($this->student)->post(route('posts.store', $this->board), ['title' => '', 'body' => '<p> </p>'])->assertSessionHasErrors('body');
        $this->actingAs($this->student)->post(route('posts.store', $this->board), ['link' => 'javascript:alert(1)'])->assertSessionHasErrors('link');
    }

    public function test_link_preview_youtube_and_html_with_ssrf_guard(): void
    {
        $this->app->bind(LinkPreview::class, fn () => new class extends LinkPreview
        {
            protected function publicIp(string $host): ?string
            {
                return str_contains($host, 'internal') ? null : '93.184.216.34';
            }
        });
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'Belajar Fotosintesis'], 200),
            'contoh.id/*' => Http::response('<html><head><title>Judul Halaman</title><meta property="og:description" content="Ringkasan"></head></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame('dQw4w9WgXcQ', LinkPreview::youtubeId('https://youtu.be/dQw4w9WgXcQ?t=3'));
        $this->assertSame('dQw4w9WgXcQ', LinkPreview::youtubeId('https://www.youtube.com/shorts/dQw4w9WgXcQ'));

        $this->actingAs($this->student)->postJson(route('posts.preview-link', $this->board), ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertOk()->assertJsonPath('type', 'youtube')->assertJsonPath('title', 'Belajar Fotosintesis');

        $this->actingAs($this->student)->postJson(route('posts.preview-link', $this->board), ['url' => 'https://contoh.id/artikel'])
            ->assertOk()->assertJsonPath('title', 'Judul Halaman')->assertJsonPath('description', 'Ringkasan');

        // alamat internal tidak diambil isinya
        $this->actingAs($this->student)->postJson(route('posts.preview-link', $this->board), ['url' => 'http://internal.sekolah/admin'])
            ->assertOk()->assertJsonPath('title', 'internal.sekolah');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'internal'));

        $lp = new LinkPreview;
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '100.64.1.1', '::1'] as $ip) {
            $this->assertFalse($lp->isPublic($ip), $ip);
        }
        $this->assertTrue($lp->isPublic('8.8.8.8'));
    }
}
