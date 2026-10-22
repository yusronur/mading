<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\User;
use Tests\TestCase;

/** Tahap 9: arsip, duplikat, folder, kolaborator, tautan bergabung, ekspor. */
class LibraryTest extends TestCase
{
    private object $school;

    private User $owner;

    private User $other;

    private User $student;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->owner = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Bu Rina']);
        $this->other = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Pak Budi']);
        $this->student = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->owner->id, 'title' => 'Refleksi', 'visibility' => 'school']));
        $this->inSchool($this->school, fn () => $this->board->posts()->create(['author_id' => $this->student->id, 'body' => '<p>Ide Ani</p>', 'status' => 'published']));
    }

    private function fresh(): Board
    {
        return $this->inSchool($this->school, fn () => $this->board->fresh());
    }

    public function test_archive_blocks_new_cards_and_can_be_restored(): void
    {
        $this->actingAs($this->other)->post(route('boards.archive', $this->board))->assertForbidden();
        $this->actingAs($this->owner)->post(route('boards.archive', $this->board), ['archive' => true])->assertSessionHas('success');
        $this->assertNotNull($this->fresh()->archived_at);

        $this->actingAs($this->student)->post(route('posts.store', $this->board), ['body' => 'baru'])->assertForbidden();
        $this->actingAs($this->owner)->get(route('teacher.dashboard'))
            ->assertInertia(fn ($p) => $p->where('boards.0.archived', true));

        $this->actingAs($this->owner)->post(route('boards.archive', $this->board), ['archive' => false]);
        $this->assertNull($this->fresh()->archived_at);
    }

    public function test_duplicate_with_and_without_posts(): void
    {
        $this->actingAs($this->owner)->post(route('boards.duplicate', $this->board), ['with_posts' => false])->assertRedirect();
        $this->actingAs($this->owner)->post(route('boards.duplicate', $this->board), ['with_posts' => true])->assertRedirect();

        $copies = $this->inSchool($this->school, fn () => Board::where('title', 'Refleksi (salinan)')->withCount('posts')->orderBy('posts_count')->get());
        $this->assertCount(2, $copies);
        $this->assertSame([0, 1], $copies->pluck('posts_count')->all());
        $this->assertNotSame($this->board->code, $copies[0]->code);

        // Guru lain hanya boleh menyalin struktur, bukan kartu siswa
        $this->actingAs($this->other)->post(route('boards.duplicate', $this->board), ['with_posts' => true])->assertRedirect();
        $mine = $this->inSchool($this->school, fn () => Board::where('owner_id', $this->other->id)->withCount('posts')->first());
        $this->assertSame(0, $mine->posts_count);

        $this->actingAs($this->student)->post(route('boards.duplicate', $this->board))->assertForbidden();
    }

    public function test_folders_are_private_to_owner(): void
    {
        $this->actingAs($this->owner)->post(route('folders.store'), ['name' => 'Kelas 7'])->assertSessionHas('success');
        $folder = $this->inSchool($this->school, fn () => BoardFolder::firstOrFail());

        $this->actingAs($this->owner)->post(route('boards.move-folder', $this->board), ['folder_id' => $folder->id])->assertSessionHasNoErrors();
        $this->assertSame($folder->id, $this->fresh()->folder_id);

        // Folder milik guru lain tidak bisa dipakai / dihapus
        $this->actingAs($this->other)->delete(route('folders.destroy', $folder))->assertForbidden();

        $this->actingAs($this->owner)->delete(route('folders.destroy', $folder));
        $this->assertNull($this->fresh()->folder_id, 'papan kembali ke Semua papan');
        $this->assertNotNull($this->fresh());
    }

    public function test_collaborator_can_manage_but_not_delete(): void
    {
        $this->actingAs($this->other)->get(route('boards.collaborators', $this->board))->assertForbidden();

        $this->actingAs($this->owner)->postJson(route('boards.collaborators.add', $this->board), ['user_id' => $this->other->id])
            ->assertOk()->assertJsonPath('collaborators.0.name', 'Pak Budi');
        $this->actingAs($this->owner)->postJson(route('boards.collaborators.add', $this->board), ['user_id' => $this->student->id])->assertNotFound();

        $this->actingAs($this->other)->get(route('teacher.dashboard'))
            ->assertInertia(fn ($p) => $p->where('boards.0.title', 'Refleksi')->where('boards.0.is_owner', false));
        $this->actingAs($this->other)->get(route('boards.show', $this->board))
            ->assertInertia(fn ($p) => $p->where('can.manage', true)->where('can.own', false));
        $this->actingAs($this->other)->delete(route('boards.destroy', $this->board))->assertForbidden();

        $this->actingAs($this->owner)->deleteJson(route('boards.collaborators.remove', [$this->board, $this->other]))->assertJsonCount(0, 'collaborators');
        $this->actingAs($this->other)->get(route('boards.show', $this->board))->assertInertia(fn ($p) => $p->where('can.manage', false));
    }

    public function test_join_link_and_exports(): void
    {
        $this->actingAs($this->student)->get(route('boards.join-link', strtolower($this->board->code)))->assertRedirect(route('boards.show', $this->board));
        $this->actingAs($this->student)->get('/gabung/ZZZZZZ')->assertRedirect(route('home'));

        $this->actingAs($this->student)->get(route('boards.export', [$this->board, 'xlsx']))->assertForbidden();
        $csv = $this->actingAs($this->owner)->get(route('boards.export', [$this->board, 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('Ide Ani', $csv->streamedContent());
        $this->assertStringContainsString('Ani', $csv->streamedContent());
        $this->actingAs($this->owner)->get(route('boards.export', [$this->board, 'xlsx']))->assertOk();

        $this->actingAs($this->student)->get(route('boards.print', $this->board))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Boards/Print')->has('posts', 1));
    }
}
