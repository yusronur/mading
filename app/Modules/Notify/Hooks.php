<?php

namespace App\Modules\Notify;

use App\Models\Comment;
use App\Models\Post;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\Reaction;
use App\Models\User;

/**
 * Penghubung kejadian ke lonceng. Seperti gamifikasi, galat di sini tidak boleh
 * menggagalkan aksi pengguna. Komentar, suka, dan pengumpulan hanya masuk lonceng (tanpa push).
 */
class Hooks
{
    public static function register(): void
    {
        Comment::created(fn (Comment $c) => self::safely(fn () => self::comment($c)));
        Reaction::created(fn (Reaction $r) => self::safely(fn () => self::reaction($r)));
        QuizAttempt::updated(fn (QuizAttempt $a) => $a->wasChanged('submitted_at') && $a->submitted_at ? self::safely(fn () => self::submitted($a)) : null);
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function comment(Comment $c): void
    {
        $post = Post::find($c->post_id);
        if ($c->status !== 'published' || ! $post || ! $post->author_id || $post->author_id === $c->author_id) {
            return;
        }
        $owner = User::find($post->author_id);
        if (! $owner || $owner->status !== 'active') {
            return;
        }
        $who = $c->is_anonymous ? 'Seseorang' : (User::find($c->author_id)?->name ?? 'Seseorang');
        $label = $post->title ?: 'kartumu';
        app(Notify::class)->bump($owner, 'comment', "c:{$post->id}", fn (int $n) => [
            $n === 1 ? "$who mengomentari $label" : "$n komentar baru di $label",
            $n === 1 ? mb_substr(strip_tags((string) $c->body), 0, 120) : null,
            route('boards.show', $post->board_id),
        ]);
    }

    private static function reaction(Reaction $r): void
    {
        $post = Post::find($r->post_id);
        if (! $post || ! $post->author_id || $post->author_id === $r->user_id) {
            return;
        }
        $owner = User::find($post->author_id);
        if (! $owner || $owner->status !== 'active') {
            return;
        }
        $label = $post->title ?: 'kartumu';
        app(Notify::class)->bump($owner, 'reaction', "r:{$post->id}", fn (int $n) => [
            $n === 1 ? "Ada yang menyukai $label" : "$n reaksi baru di $label",
            null,
            route('boards.show', $post->board_id),
        ]);
    }

    private static function submitted(QuizAttempt $a): void
    {
        $s = QuizSession::find($a->session_id);
        if (! $s || $s->mode !== 'self' || ! $s->host_id) {
            return;
        }
        $teacher = User::find($s->host_id);
        if (! $teacher || $teacher->status !== 'active') {
            return;
        }
        app(Notify::class)->bump($teacher, 'submitted', "s:{$s->id}", fn (int $n) => [
            "$n siswa mengumpulkan {$s->title}", null, route('assignments.show', $s->id),
        ]);
    }
}
