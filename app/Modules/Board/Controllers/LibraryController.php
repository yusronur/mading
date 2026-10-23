<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\Post;
use App\Models\User;
use App\Modules\Board\BoardCopier;
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

        $copy = app(BoardCopier::class)->copy($board, $user, ['title' => Str::limit($board->title.' (salinan)', 150, '')], $withPosts ? 'all' : 'none');

        AuditLog::record('board.duplicated', $copy, ['from' => $board->id, 'with_posts' => $withPosts]);

        return redirect()->route('boards.show', $copy)->with('success', 'Papan disalin dengan kode baru '.$copy->code.'.');
    }

    /** Salin struktur papan ke beberapa rombel sekaligus, mis. untuk tahun ajaran baru (Tahap 18). */
    public function copyToClasses(Request $request, Board $board, BoardCopier $copier): RedirectResponse
    {
        Gate::authorize('view', $board);
        Gate::authorize('create', Board::class);
        $data = $request->validate([
            'class_ids' => ['required', 'array', 'min:1', 'max:20'],
            'class_ids.*' => ['uuid', Rule::exists('classes', 'id')],
            'posts' => ['required', Rule::in(['none', 'staff'])],
        ], ['class_ids.required' => 'Pilih minimal satu rombel.'], ['class_ids' => 'rombel']);

        $classes = \App\Models\SchoolClass::whereIn('id', $data['class_ids'])->get();
        foreach ($classes as $class) {
            $copy = $copier->copy($board, $request->user(), ['visibility' => 'class', 'class_id' => $class->id], Gate::allows('manage', $board) ? $data['posts'] : 'none');
            AuditLog::record('board.copied_to_class', $copy, ['from' => $board->id, 'class' => $class->name]);
        }

        return back()->with('success', "Papan disalin ke {$classes->count()} rombel: ".$classes->pluck('name')->implode(', ').'.');
    }

    /** Simpan papan sebagai template pribadi atau bersama sekolah. */
    public function saveTemplate(Request $request, Board $board, BoardCopier $copier): RedirectResponse
    {
        Gate::authorize('manage', $board);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'shared' => ['boolean'],
            'with_posts' => ['boolean'],
        ], [], ['title' => 'nama template']);

        $tpl = $copier->copy($board, $request->user(), [
            'title' => strip_tags($data['title']), 'visibility' => 'private', 'class_id' => null,
            'is_template' => true, 'template_shared' => (bool) ($data['shared'] ?? false),
        ], ($data['with_posts'] ?? false) ? 'staff' : 'none');
        AuditLog::record('board.template_saved', $tpl, ['from' => $board->id]);

        return back()->with('success', 'Template "'.$tpl->title.'" disimpan. Pakai lewat tombol "+ Papan baru".');
    }

    /** Buat papan baru dari template. */
    public function useTemplate(Request $request, Board $board, BoardCopier $copier): RedirectResponse
    {
        Gate::authorize('create', Board::class);
        $user = $request->user();
        abort_unless($board->is_template && ($board->owner_id === $user->id || $board->template_shared || $user->isAdmin()), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'visibility' => ['required', Rule::in(array_keys(Board::VISIBILITIES))],
            'class_id' => ['nullable', 'required_if:visibility,class', 'uuid', Rule::exists('classes', 'id')],
        ], ['class_id.required_if' => 'Pilih rombel untuk papan satu rombel.'], ['title' => 'judul', 'class_id' => 'rombel']);

        $copy = $copier->copy($board, $user, [
            'title' => strip_tags($data['title']), 'visibility' => $data['visibility'],
            'class_id' => $data['visibility'] === 'class' ? $data['class_id'] : null,
        ], 'all');
        AuditLog::record('board.from_template', $copy, ['template' => $board->id]);

        return redirect()->route('boards.show', $copy)->with('success', 'Papan dibuat dari template. Kode: '.$copy->code.'.');
    }

    public function shareTemplate(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('own', $board);
        abort_unless($board->is_template, 404);
        $board->forceFill(['template_shared' => $request->boolean('shared')])->saveQuietly();

        return back()->with('success', $board->template_shared ? 'Template dibagikan ke semua guru di sekolah.' : 'Template kembali pribadi.');
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
