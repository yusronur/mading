<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Komentar (INT-01) dan reaksi (INT-02) pada kartu. */
class InteractionController extends Controller
{
    public function comments(Request $request, Board $board, Post $post): JsonResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('viewPost', [$board, $post]);

        return response()->json(['comments' => $this->thread($request->user(), $board, $post)]);
    }

    public function __construct(private \App\Modules\Board\Moderation $moderation) {}

    public function storeComment(Request $request, Board $board, Post $post): JsonResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('viewPost', [$board, $post]);
        Gate::authorize('comment', $board);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'is_anonymous' => ['boolean'],
        ], [], ['body' => 'komentar']);

        $body = trim(strip_tags($data['body']));
        abort_if($body === '', 422, 'Komentar kosong.');

        // Utas satu tingkat: balasan atas balasan menempel ke komentar induknya
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parent = $post->comments()->whereKey($data['parent_id'])->firstOrFail();
            $parentId = $parent->parent_id ?? $parent->id;
        }

        $decision = $this->moderation->decide($board, $request->user(), $body);

        DB::transaction(function () use ($request, $board, $post, $data, $body, $parentId, $decision) {
            $post->comments()->create([
                'author_id' => $request->user()->id,
                'parent_id' => $parentId,
                'body' => $body,
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false) && $board->setting('allow_anonymous', false),
                'status' => $decision['status'],
                'moderation_reason' => $decision['moderation_reason'],
            ]);
            $decision['status'] === 'published' ? $post->increment('comment_count') : $post->touch();
        });

        return response()->json([
            'comments' => $this->thread($request->user(), $board, $post),
            'held' => $decision['status'] === 'pending',
        ], 201);
    }

    public function destroyComment(Request $request, Board $board, Comment $comment): JsonResponse
    {
        $post = Post::findOrFail($comment->post_id);
        $this->ensureBelongs($board, $post);
        Gate::authorize('deleteComment', [$board, $comment]);

        DB::transaction(function () use ($comment, $post) {
            $removed = ($comment->status === 'published' ? 1 : 0) + Comment::where('parent_id', $comment->id)->where('status', 'published')->count();
            Comment::where('parent_id', $comment->id)->delete();
            $comment->delete();
            $post->forceFill(['comment_count' => max(0, $post->comment_count - $removed)])->saveQuietly();
            $post->touch();
        });

        return response()->json(['comments' => $this->thread($request->user(), $board, $post)]);
    }

    /** Beri/ubah/hapus reaksi sesuai jenis reaksi papan. value null = hapus. */
    public function react(Request $request, Board $board, Post $post): RedirectResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('viewPost', [$board, $post]);
        Gate::authorize('interact', $board);

        $kind = $board->reactionKind();
        abort_if($kind === 'none', 422, 'Reaksi dimatikan di papan ini.');
        if ($kind === 'grade') {
            Gate::authorize('manage', $board);
        }

        $rules = [
            'like' => ['nullable', Rule::in([1])],
            'star' => ['nullable', 'integer', 'between:1,5'],
            'vote' => ['nullable', Rule::in([-1, 1])],
            'grade' => ['nullable', 'integer', 'between:0,100'],
        ];
        $value = $request->validate(['value' => $rules[$kind]], [], ['value' => 'nilai'])['value'] ?? null;

        $match = ['post_id' => $post->id, 'user_id' => $request->user()->id, 'kind' => $kind];

        if ($value === null) {
            Reaction::where($match)->delete();
        } else {
            Reaction::updateOrCreate($match, ['value' => (int) $value]);
        }

        $post->forceFill(['reaction_count' => Reaction::where('post_id', $post->id)->where('kind', $kind)->count()])->saveQuietly();
        $post->touch();

        return back();
    }

    private function thread(User $user, Board $board, Post $post): array
    {
        $canManage = Gate::forUser($user)->allows('manage', $board);
        $canModerate = Gate::forUser($user)->allows('moderate', $board);

        $rows = $post->comments()
            ->when(! $canModerate, fn ($q) => $q->where(fn ($w) => $w->where('status', 'published')->orWhere('author_id', $user->id)))
            ->with('author:id,name,role')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $map = fn (Comment $c) => [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'body' => $c->body,
            'status' => $c->status,
            'moderation_reason' => $canModerate ? $c->moderation_reason : null,
            'author' => (! $c->is_anonymous || $canManage || $c->author_id === $user->id) ? $c->author?->name : 'Anonim',
            'is_anonymous' => $c->is_anonymous,
            'is_teacher' => (! $c->is_anonymous || $canManage) && $c->author?->role !== User::ROLE_STUDENT,
            'is_mine' => $c->author_id === $user->id,
            'can_delete' => Gate::forUser($user)->allows('deleteComment', [$board, $c]),
            'created_at' => $c->created_at->toIso8601String(),
        ];

        $roots = $rows->whereNull('parent_id')->values();

        return $roots->map(fn (Comment $c) => $map($c) + [
            'replies' => $rows->where('parent_id', $c->id)->values()->map($map)->all(),
        ])->all();
    }

    private function ensureBelongs(Board $board, Post $post): void
    {
        abort_unless($post->board_id === $board->id, 404);
    }
}
