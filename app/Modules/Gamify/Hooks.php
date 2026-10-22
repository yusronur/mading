<?php

namespace App\Modules\Gamify;

use App\Events\LiveQuizFinished;
use App\Models\ActivityResponse;
use App\Models\Comment;
use App\Models\Post;
use App\Models\QuizAttempt;
use App\Models\Reaction;
use App\Models\User;
use App\Modules\Quiz\LiveQuiz;
use Illuminate\Support\Facades\Event;

/**
 * Penghubung modul lain ke gamifikasi. Semua lewat observer/event, sehingga
 * modul papan dan kuis tidak perlu tahu soal XP, dan galat di sini tidak
 * boleh menggagalkan aksi siswa.
 */
class Hooks
{
    public static function register(): void
    {
        Post::created(fn (Post $p) => self::safely(fn () => self::post($p)));
        Post::updated(fn (Post $p) => $p->wasChanged('status') ? self::safely(fn () => self::post($p)) : null);

        Comment::created(fn (Comment $c) => self::safely(fn () => self::comment($c)));
        Comment::updated(fn (Comment $c) => $c->wasChanged('status') ? self::safely(fn () => self::comment($c)) : null);

        Reaction::created(fn (Reaction $r) => self::safely(fn () => self::reaction($r)));
        ActivityResponse::created(fn (ActivityResponse $a) => self::safely(fn () => self::activity($a)));
        QuizAttempt::updated(fn (QuizAttempt $a) => $a->wasChanged('submitted_at') && $a->submitted_at ? self::safely(fn () => self::attempt($a)) : null);

        Event::listen(LiveQuizFinished::class, fn (LiveQuizFinished $e) => self::safely(fn () => self::podium($e)));
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function student(?string $id): ?User
    {
        return $id ? User::find($id) : null;
    }

    private static function post(Post $p): void
    {
        if ($p->status !== 'published' || ! ($u = self::student($p->author_id))) {
            return;
        }
        app(Gamify::class)->award($u, 'post', 'post', $p->id);
    }

    private static function comment(Comment $c): void
    {
        if ($c->status !== 'published' || ! ($u = self::student($c->author_id))) {
            return;
        }
        $post = Post::find($c->post_id);
        if (! $post || $post->author_id === $c->author_id) {
            return; // komentar di kartu sendiri tidak dihargai
        }
        if (mb_strlen(trim(strip_tags((string) $c->body))) < (int) config('gamify.min_comment_chars')) {
            return;
        }
        app(Gamify::class)->award($u, 'comment', 'comment', $c->id);
    }

    private static function reaction(Reaction $r): void
    {
        if ($r->kind === 'grade' || $r->value <= 0) {
            return;
        }
        $post = Post::find($r->post_id);
        if (! $post || $post->author_id === $r->user_id) {
            return;
        }
        $g = app(Gamify::class);
        // Per kartu sekali saja, sehingga suka-batal-suka tidak mengulang XP
        if ($u = self::student($r->user_id)) {
            $g->award($u, 'reaction', 'post', $post->id);
        }

        // Ide berbintang: kartu mendapat 5 reaksi positif dari orang lain
        $others = Reaction::where('post_id', $post->id)->where('user_id', '!=', $post->author_id)->where('value', '>', 0)->where('kind', '!=', 'grade')->count();
        if ($others >= 5 && ($author = self::student($post->author_id))) {
            $g->mark($author, 'idea_star', 'post', $post->id);
        }
    }

    private static function activity(ActivityResponse $a): void
    {
        if ($u = self::student($a->user_id)) {
            app(Gamify::class)->award($u, 'activity', 'activity', $a->activity_id);
        }
    }

    private static function attempt(QuizAttempt $a): void
    {
        if (! ($u = self::student($a->user_id)) || $a->max_score <= 0) {
            return;
        }
        $g = app(Gamify::class);
        $live = $a->session?->isLive() ?? false;
        $ratio = max(0, min(1, $a->score / $a->max_score));
        $points = (int) config($live ? 'gamify.xp.live' : 'gamify.xp.quiz') + (int) round($ratio * (int) config('gamify.quiz_bonus_max'));
        $g->award($u, $live ? 'live' : 'quiz', 'attempt', $a->id, $points);
        if ($a->grade() === 100) {
            $g->mark($u, 'ace', 'attempt', $a->id);
        }
    }

    private static function podium(LiveQuizFinished $e): void
    {
        $g = app(Gamify::class);
        $board = app(LiveQuiz::class)->leaderboard($e->session, 3)['players'];
        foreach ($board as $row) {
            $bonus = config("gamify.xp.podium.{$row['rank']}");
            if (! $bonus || $row['guest'] || $row['score'] <= 0 || ! ($u = self::student($row['user_id']))) {
                continue;
            }
            $g->award($u, 'podium', 'session', $e->session->id, $bonus);
            $g->mark($u, 'podium', 'session', $e->session->id);
        }
    }
}
