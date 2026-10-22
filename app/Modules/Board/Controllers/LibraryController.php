<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\Post;
use App\Models\User;
use App\Modules\Board\BoardPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pustaka papan guru: arsip, duplikat, folder, kolaborator (BRD-01, 14, 16, 19). */
class LibraryController extends Controller
{
    public function archive(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('own', $board);
        $archive = $request->boolean('archive', true);
        $board->forceFill(['archived_at' => $archive ? now() : null])->save();
        AuditLog::record('board.archived', $board, ['archived' => $archive]);

        return back()->with('success', $archive ? 'Papan diarsipkan. Siswa masih bisa membaca, tetapi tidak bisa menambah kartu.' : 'Papan dipulihkan dari arsip.');
    }

    /** Remake: salin papan dengan atau tanpa isi (BRD-14). */
    public function duplicate(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('view', $board);
        Gate::authorize('create', Board::class);
        // Menyalin kartu siswa hanya boleh oleh pengelola papan asal
        $withPosts = $request->boolean('with_posts') && Gate::allows('manage', $board);
        $user = $request->user();

        $copy = DB::transaction(function () use ($board, $withPosts, $user) {
            $copy = $board->replicate(['code', 'secret_token', 'archived_at', 'folder_id']);
            $copy->fill([
                'owner_id' => $user->id,
                'title' => Str::limit($board->title.' (salinan)', 150, ''),
                'code' => Board::generateCode(),
                'secret_token' => $board->visibility === 'link' ? Str::random(32) : null,
                'is_frozen' => false,
            ]);
            $copy->save();

            $sectionMap = [];
            foreach ($board->sections()->get() as $section) {
                $new = $section->replicate();
                $new->board_id = $copy->id;
                $new->save();
                $sectionMap[$section->id] = $new->id;
            }

            if ($withPosts) {
                $disk = Attachment::disk();
                $posts = $board->posts()->where('status', 'published')->with('attachments')->get();

                foreach ($posts as $post) {
                    $newPost = $post->replicate(['comment_count', 'reaction_count', 'report_count']);
                    $newPost->fill([
                        'board_id' => $copy->id,
                        'section_id' => $sectionMap[$post->section_id] ?? null,
                        'link' => $post->link ? array_diff_key($post->link, ['image_path' => true]) : null,
                    ]);
                    $newPost->save();

                    foreach ($post->attachments as $a) {
                        $dir = 'schools/'.$copy->school_id.'/boards/'.$copy->id;
                        $name = (string) Str::uuid7();
                        $path = $dir.'/'.$name.'.'.pathinfo($a->path, PATHINFO_EXTENSION);
                        $thumb = $a->thumb_path ? $dir.'/'.$name.'-thumb.webp' : null;
                        if (! $disk->exists($a->path)) {
                            continue;
                        }
                        $disk->copy($a->path, $path);
                        if ($thumb && $disk->exists($a->thumb_path)) {
                            $disk->copy($a->thumb_path, $thumb);
                        }
                        $na = $a->replicate();
                        $na->fill(['board_id' => $copy->id, 'attachable_id' => $newPost->id, 'path' => $path, 'thumb_path' => $thumb, 'owner_id' => $a->owner_id]);
                        $na->save();
                    }
                }
            }

            return $copy;
        });

        AuditLog::record('board.duplicated', $copy, ['from' => $board->id, 'with_posts' => $withPosts]);

        return redirect()->route('boards.show', $copy)->with('success', 'Papan disalin dengan kode baru '.$copy->code.'.');
    }

    public function moveToFolder(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('own', $board);
        $data = $request->validate(['folder_id' => ['nullable', 'uuid', Rule::exists('board_folders', 'id')->where('owner_id', $request->user()->id)]]);
        $board->forceFill(['folder_id' => $data['folder_id'] ?? null])->saveQuietly();

        return back()->with('success', 'Papan dipindah.');
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        Gate::authorize('create', Board::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => 'nama folder']);
        BoardFolder::create(['owner_id' => $request->user()->id, 'name' => strip_tags($data['name'])]);

        return back()->with('success', 'Folder dibuat.');
    }

    public function updateFolder(Request $request, BoardFolder $folder): RedirectResponse
    {
        abort_unless($folder->owner_id === $request->user()->id, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => 'nama folder']);
        $folder->update(['name' => strip_tags($data['name'])]);

        return back();
    }

    public function destroyFolder(Request $request, BoardFolder $folder): RedirectResponse
    {
        abort_unless($folder->owner_id === $request->user()->id, 403);
        $folder->delete(); // papan di dalamnya kembali ke "Semua papan"

        return back()->with('success', 'Folder dihapus. Papan di dalamnya tidak ikut terhapus.');
    }

    /** Daftar kolaborator dan guru yang bisa ditambahkan. */
    public function collaborators(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('own', $board);
        $q = trim((string) $request->query('q', ''));

        $current = DB::table('board_members')->join('users', 'users.id', '=', 'board_members.user_id')
            ->where('board_members.board_id', $board->id)->where('board_members.role', 'editor')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.username']);

        $candidates = $q === '' ? [] : User::whereIn('role', [User::ROLE_TEACHER, User::ROLE_ADMIN])
            ->where('status', 'active')
            ->where('id', '!=', $board->owner_id)
            ->whereNotIn('id', $current->pluck('id'))
            ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('username', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'username']);

        return response()->json(['collaborators' => $current, 'candidates' => $candidates]);
    }

    public function addCollaborator(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('own', $board);
        $data = $request->validate(['user_id' => ['required', 'uuid']]);
        $teacher = User::whereKey($data['user_id'])->whereIn('role', [User::ROLE_TEACHER, User::ROLE_ADMIN])->firstOrFail();
        abort_if($teacher->id === $board->owner_id, 422, 'Pemilik papan sudah punya akses penuh.');

        DB::table('board_members')->updateOrInsert(
            ['board_id' => $board->id, 'user_id' => $teacher->id],
            ['school_id' => $board->school_id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()],
        );
        BoardPolicy::flush();
        AuditLog::record('board.collaborator_added', $board, ['user' => $teacher->username]);

        return $this->collaborators($request, $board);
    }

    public function removeCollaborator(Request $request, Board $board, User $user): JsonResponse
    {
        Gate::authorize('own', $board);
        DB::table('board_members')->where('board_id', $board->id)->where('user_id', $user->id)->where('role', 'editor')->delete();
        BoardPolicy::flush();
        AuditLog::record('board.collaborator_removed', $board, ['user' => $user->username]);

        return $this->collaborators($request, $board);
    }
}
