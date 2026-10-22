<?php

namespace App\Modules\Board;

use App\Models\Board;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hak akses papan (Blueprint Bab 6). Data sekolah lain sudah tersaring oleh
 * global scope + RLS, jadi di sini hanya aturan di dalam satu sekolah.
 *
 * Peran:
 * - pemilik/admin sekolah: semua (own)
 * - editor (kolaborator guru): mengelola isi dan pengaturan, tidak menghapus papan
 * - pengunjung: melihat; menulis bila diizinkan; moderasi bila hak "moderate"
 */
class BoardPolicy
{
    /** Cache peran keanggotaan per permintaan: "board|user" => role|null */
    private static array $roles = [];

    public static function flush(): void
    {
        self::$roles = [];
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_TEACHER, User::ROLE_ADMIN);
    }

    public function view(User $user, Board $board): bool
    {
        if ($this->manage($user, $board)) {
            return true;
        }

        return match ($board->visibility) {
            'school' => true,
            'class' => $user->isTeacher() || $this->isClassMember($user, $board->class_id),
            'link' => $this->role($user, $board) !== null,
            default => false,
        };
    }

    public function post(User $user, Board $board): bool
    {
        if (! $this->view($user, $board) || $board->archived_at) {
            return false;
        }

        if ($this->manage($user, $board)) {
            return true;
        }

        return ! $board->is_frozen && in_array($board->visitor_permission, ['write', 'moderate'], true);
    }

    /** Pemilik papan atau admin: hapus papan, atur kolaborator. */
    public function own(User $user, Board $board): bool
    {
        return $user->isAdmin() || $board->owner_id === $user->id;
    }

    public function manage(User $user, Board $board): bool
    {
        return $this->own($user, $board) || $this->role($user, $board) === 'editor';
    }

    /** Menyetujui, menyembunyikan, dan menolak kartu/komentar (MOD-05). */
    public function moderate(User $user, Board $board): bool
    {
        return $this->manage($user, $board)
            || ($board->visitor_permission === 'moderate' && ! $board->archived_at && $this->view($user, $board));
    }

    /** Komentar dan reaksi: semua yang bisa melihat, selama papan tidak dibekukan. */
    public function interact(User $user, Board $board): bool
    {
        if (! $this->view($user, $board) || $board->archived_at) {
            return false;
        }

        return $this->manage($user, $board) || ! $board->is_frozen;
    }

    public function comment(User $user, Board $board): bool
    {
        return $this->interact($user, $board) && ($board->allowsComments() || $this->manage($user, $board));
    }

    public function deleteComment(User $user, Board $board, Comment $comment): bool
    {
        return $this->moderate($user, $board) || ($comment->author_id === $user->id && ! $board->is_frozen);
    }

    public function viewPost(User $user, Board $board, Post $post): bool
    {
        if (! $this->view($user, $board)) {
            return false;
        }

        return $post->status === 'published' || $post->author_id === $user->id || $this->moderate($user, $board);
    }

    public function updatePost(User $user, Board $board, Post $post): bool
    {
        if ($this->manage($user, $board)) {
            return true;
        }

        return $post->author_id === $user->id && ! $board->is_frozen && ! $board->archived_at && $this->view($user, $board);
    }

    public function deletePost(User $user, Board $board, Post $post): bool
    {
        if ($this->moderate($user, $board)) {
            return true;
        }

        return $post->author_id === $user->id && ! $board->is_frozen;
    }

    public function role(User $user, Board $board): ?string
    {
        $key = $board->id.'|'.$user->id;

        if (! array_key_exists($key, self::$roles)) {
            self::$roles[$key] = DB::table('board_members')->where('board_id', $board->id)->where('user_id', $user->id)->value('role');
        }

        return self::$roles[$key];
    }

    private function isClassMember(User $user, ?string $classId): bool
    {
        if (! $classId) {
            return false;
        }

        return DB::table('class_members')->where('class_id', $classId)->where('user_id', $user->id)->exists();
    }
}
