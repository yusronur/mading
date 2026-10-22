<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Moderasi kartu dan komentar, laporan siswa (MOD-01, MOD-03, MOD-05). */
class ModerationController extends Controller
{
    /** Jumlah laporan yang membuat kartu otomatis ditahan untuk diperiksa. */
    public const AUTO_HOLD_REPORTS = 3;

    /** Antrean untuk moderator: kartu dan komentar yang menunggu/dilaporkan. */
    public function queue(Board $board): JsonResponse
    {
        Gate::authorize('moderate', $board);

        $comments = Comment::query()
            ->whereIn('post_id', $board->posts()->select('id'))
            ->where('status', 'pending')
            ->with(['author:id,name', 'post:id,title'])
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Comment $c) => [
                'id' => $c->id,
                'post_id' => $c->post_id,
                'post_title' => $c->post?->title,
                'body' => $c->body,
                'author' => $c->author?->name,
                'reason' => $c->moderation_reason,
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        $reports = PostReport::query()
            ->whereIn('post_id', $board->posts()->select('id'))
            ->whereNull('resolved_at')
            ->join('users', 'users.id', '=', 'post_reports.user_id')
            ->orderBy('post_reports.created_at')
            ->get(['post_reports.post_id', 'post_reports.reason', 'users.name'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['by' => $r->name, 'reason' => $r->reason])->values());

        return response()->json(['comments' => $comments, 'reports' => $reports]);
    }

    public function post(Request $request, Board $board, Post $post): RedirectResponse
    {
        abort_unless($post->board_id === $board->id, 404);
        Gate::authorize('moderate', $board);
        $action = $request->validate(['action' => ['required', Rule::in(['approve', 'hide', 'unhide', 'reject'])]])['action'];

        DB::transaction(function () use ($action, $post) {
            if ($action === 'reject') {
                $post->attachments()->get()->each->purge();
                $post->delete();
            } else {
                $post->forceFill([
                    'status' => $action === 'hide' ? 'hidden' : 'published',
                    'moderation_reason' => $action === 'hide' ? 'disembunyikan' : null,
                ])->save();
            }

            // Laporan dianggap selesai setelah kartu ditindaklanjuti
            PostReport::where('post_id', $post->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
            if ($action !== 'reject') {
                $post->forceFill(['report_count' => 0])->saveQuietly();
            }
        });

        AuditLog::record('post.moderated', $post, ['action' => $action, 'board_id' => $board->id]);
        $board->touch();

        return back()->with('success', [
            'approve' => 'Kartu disetujui dan tampil di papan.',
            'hide' => 'Kartu disembunyikan dari siswa.',
            'unhide' => 'Kartu ditampilkan lagi.',
            'reject' => 'Kartu ditolak dan dihapus.',
        ][$action]);
    }

    public function comment(Request $request, Board $board, Comment $comment): JsonResponse
    {
        $post = Post::findOrFail($comment->post_id);
        abort_unless($post->board_id === $board->id, 404);
        Gate::authorize('moderate', $board);
        $action = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])]])['action'];

        DB::transaction(function () use ($action, $comment, $post) {
            if ($action === 'approve' && $comment->status !== 'published') {
                $comment->forceFill(['status' => 'published', 'moderation_reason' => null])->save();
                $post->increment('comment_count');
            } elseif ($action === 'reject') {
                if ($comment->status === 'published') {
                    $post->decrement('comment_count');
                }
                $comment->delete();
                $post->touch();
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Siswa melaporkan kartu yang tidak pantas (MOD-03). */
    public function report(Request $request, Board $board, Post $post): RedirectResponse
    {
        abort_unless($post->board_id === $board->id, 404);
        Gate::authorize('viewPost', [$board, $post]);
        abort_if($post->author_id === $request->user()->id, 422, 'Tidak bisa melaporkan kartu sendiri.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']], [], ['reason' => 'alasan']);

        DB::transaction(function () use ($request, $post, $data) {
            $report = PostReport::firstOrCreate(
                ['post_id' => $post->id, 'user_id' => $request->user()->id],
                ['reason' => isset($data['reason']) ? strip_tags($data['reason']) : null],
            );

            if (! $report->wasRecentlyCreated) {
                return;
            }

            $open = PostReport::where('post_id', $post->id)->whereNull('resolved_at')->count();
            $post->forceFill(['report_count' => $open])->save();

            // Banyak laporan: tahan dulu sampai guru memeriksa
            if ($open >= self::AUTO_HOLD_REPORTS && $post->status === 'published') {
                $post->forceFill(['status' => 'pending', 'moderation_reason' => 'banyak dilaporkan'])->save();
            }
        });

        $board->touch();

        return back()->with('success', 'Terima kasih, laporanmu terkirim ke guru.');
    }

    /** Buka papan lewat tautan rahasia: jadi anggota papan (MOD-04). */
    public function openLink(Request $request, string $token): RedirectResponse
    {
        $board = Board::where('secret_token', $token)->where('visibility', 'link')->whereNull('archived_at')->first();
        abort_unless($board && strlen($token) >= 20, 404);

        $this->addMember($request->user(), $board);

        return redirect()->route('boards.show', $board);
    }

    public static function addMember(User $user, Board $board): void
    {
        DB::table('board_members')->insertOrIgnore([
            'board_id' => $board->id,
            'user_id' => $user->id,
            'school_id' => $board->school_id,
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Modules\Board\BoardPolicy::flush();
    }
}
