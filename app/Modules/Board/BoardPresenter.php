<?php

namespace App\Modules\Board;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Menyusun data papan untuk halaman (dipakai BoardController). */
class BoardPresenter
{
    public function board(Board $board): array
    {
        return [
            'id' => $board->id,
            'title' => $board->title,
            'description' => $board->description,
            'icon' => $board->icon,
            'background' => $board->background,
            'archived' => $board->archived_at !== null,
            'join_url' => route('boards.join-link', $board->code),
            'code' => $board->code,
            'format' => $board->format,
            'sort' => $board->sortMode(),
            'visibility' => $board->visibility,
            'visibility_label' => Board::VISIBILITIES[$board->visibility] ?? $board->visibility,
            'class_name' => $board->schoolClass?->name,
            'owner' => $board->owner?->name,
            'is_frozen' => $board->is_frozen,
            'allow_anonymous' => (bool) $board->setting('allow_anonymous', false),
            'reaction' => $board->reactionKind(),
            'allow_comments' => $board->allowsComments(),
            'require_approval' => $board->requiresApproval(),
            'max_posts' => $board->maxPostsPerStudent(),
            'schedule' => $board->scheduleState(),
            'opens_at' => $board->setting('opens_at'),
            'closes_at' => $board->setting('closes_at'),
            'rubric' => $board->rubric(),
            'is_template' => (bool) $board->is_template,
            'checklist_all' => (bool) $board->setting('checklist_all', false),
        ];
    }

    public function sections(Board $board): array
    {
        return $board->sections()->get(['id', 'title', 'position'])
            ->map(fn ($s) => ['id' => $s->id, 'title' => $s->title])->all();
    }

    /** Kartu terurut: disematkan dulu, lalu sesuai mode urutan papan. */
    public function orderedPosts(Board $board): Builder
    {
        $query = $board->posts()->getQuery()->orderByDesc('is_pinned');

        return match ($board->sortMode()) {
            // id UUID v7 terurut waktu: pemecah seri bila dibuat di detik yang sama
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'manual' => $query->orderBy('position')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    public function posts(Board $board, User $user): array
    {
        $canManage = Gate::forUser($user)->allows('manage', $board);
        $canModerate = Gate::forUser($user)->allows('moderate', $board);
        $checkAll = $board->setting('checklist_all', false) && Gate::forUser($user)->allows('post', $board);
        $kind = $board->reactionKind();

        // Sebelum jadwal buka, siswa belum melihat kartu apa pun
        if (! $canManage && $board->scheduleState() === 'before') {
            return [];
        }

        // Moderator melihat semua; siswa melihat yang tayang + miliknya sendiri yang ditahan
        $posts = $this->orderedPosts($board)
            ->when(! $canModerate, fn ($q) => $q->where(fn ($w) => $w->where('status', 'published')->orWhere('author_id', $user->id)))
            ->with(['author:id,name,role', 'attachments'])
            ->limit(500)
            ->get();

        $reactions = $this->reactionSummary($posts->pluck('id')->all(), $kind, $user, $canManage);

        // Komentar dari orang lain pada kartu milik pengguna: dasar notifikasi
        // "komentar baru di kartumu" (hanya yang tayang; komentar sendiri tidak dihitung)
        $mineIds = $posts->where('author_id', $user->id)->pluck('id')->all();
        $othersComments = $mineIds
            ? \App\Models\Comment::whereIn('post_id', $mineIds)->where('status', 'published')->where('author_id', '!=', $user->id)
                ->groupBy('post_id')->selectRaw('post_id, count(*) as n')->pluck('n', 'post_id')
            : collect();

        // Reaksi (hati/bintang/dll.) dari orang lain pada kartu sendiri
        $othersReactions = $mineIds && $kind !== 'none'
            ? DB::table('reactions')->whereIn('post_id', $mineIds)->where('kind', $kind)->where('user_id', '!=', $user->id)
                ->groupBy('post_id')->selectRaw('post_id, count(*) as n')->pluck('n', 'post_id')
            : collect();

        // Nilai rubrik: hanya pengelola dan penulis kartu yang melihat
        $grades = $board->rubric() || $canManage
            ? \App\Models\PostGrade::whereIn('post_id', $posts->pluck('id'))->with('grader:id,name')->get()->keyBy('post_id')
            : collect();

        return $posts->map(function (Post $p) use ($user, $canManage, $canModerate, $checkAll, $grades, $board, $reactions, $othersComments, $othersReactions) {
                // Identitas asli post anonim tidak pernah dikirim ke siswa lain
                $showAuthor = ! $p->is_anonymous || $canManage || $p->author_id === $user->id;

                return [
                    'id' => $p->id,
                    'section_id' => $p->section_id,
                    'title' => $p->title,
                    'body' => $p->body,
                    'color' => $p->color,
                    'link' => $this->link($board, $p),
                    'checklist' => $p->checklist,
                    'grade' => ($canManage || $p->author_id === $user->id) && isset($grades[$p->id]) ? $grades[$p->id]->toCard() : null,
                    'can_check' => $canManage || ($p->author_id === $user->id && ! $board->isLocked() && ! $board->archived_at) || $checkAll,
                    'attachments' => $p->attachments->map->toCard()->all(),
                    'edited' => $p->edited_at !== null,
                    'is_pinned' => $p->is_pinned,
                    'is_anonymous' => $p->is_anonymous,
                    'created_at' => $p->created_at->toIso8601String(),
                    'author' => $showAuthor ? $p->author?->name : 'Anonim',
                    'is_teacher' => $showAuthor && $p->author?->role !== User::ROLE_STUDENT,
                    'is_mine' => $p->author_id === $user->id,
                    'comment_count' => $p->comment_count,
                    'others_comments' => (int) ($othersComments[$p->id] ?? 0),
                    'others_reactions' => (int) ($othersReactions[$p->id] ?? 0),
                    'status' => $p->status,
                    'moderation_reason' => ($canModerate || $p->author_id === $user->id) ? $p->moderation_reason : null,
                    'report_count' => $canModerate ? $p->report_count : 0,
                    'reaction' => $reactions[$p->id] ?? null,
                    // Sama dengan BoardPolicy::updatePost/deletePost, dihitung tanpa query per kartu
                    'can_edit' => $canManage || ($p->author_id === $user->id && ! $board->isLocked() && ! $board->archived_at),
                    'can_delete' => $canModerate || ($p->author_id === $user->id && ! $board->isLocked()),
                    'can_report' => $p->author_id !== $user->id && ! $canModerate && $p->status === 'published',
                ];
            })->all();
    }

    /**
     * Ringkasan reaksi per kartu sesuai jenis reaksi papan. Nilai angka (grade)
     * hanya terlihat oleh guru pengelola dan penulis kartu.
     */
    private function reactionSummary(array $postIds, string $kind, User $user, bool $canManage): array
    {
        if ($kind === 'none' || ! $postIds) {
            return [];
        }

        $rows = DB::table('reactions')->whereIn('post_id', $postIds)->where('kind', $kind)
            ->groupBy('post_id')
            ->selectRaw('post_id, count(*) as n, sum(value) as total, avg(value) as average, sum(case when value > 0 then 1 else 0 end) as up, sum(case when value < 0 then 1 else 0 end) as down')
            ->get()->keyBy('post_id');

        $mine = DB::table('reactions')->whereIn('post_id', $postIds)->where('kind', $kind)->where('user_id', $user->id)
            ->pluck('value', 'post_id');

        $authors = $kind === 'grade' ? Post::whereIn('id', $postIds)->pluck('author_id', 'id') : collect();

        $out = [];
        foreach ($postIds as $id) {
            $r = $rows[$id] ?? null;
            $summary = [
                'kind' => $kind,
                'count' => (int) ($r->n ?? 0),
                'mine' => isset($mine[$id]) ? (int) $mine[$id] : null,
            ];

            $summary += match ($kind) {
                'star' => ['average' => $r ? round((float) $r->average, 1) : null],
                'vote' => ['score' => (int) ($r->total ?? 0), 'up' => (int) ($r->up ?? 0), 'down' => (int) ($r->down ?? 0)],
                'grade' => ['grade' => ($canManage || ($authors[$id] ?? null) === $user->id) && $r ? (int) round((float) $r->average) : null],
                default => [],
            };

            $out[$id] = $summary;
        }

        return $out;
    }

    private function link(Board $board, Post $p): ?array
    {
        if (! $p->link) {
            return null;
        }
        $link = $p->link;
        $link['image_url'] = ! empty($link['image_path']) ? route('posts.link-image', [$board->id, $p->id]) : null;
        unset($link['image_path'], $link['image']);

        return $link;
    }

    /**
     * Penanda versi papan untuk pembaruan cepat (ADR-014). Berubah bila ada
     * kartu/kolom baru, diubah, dihapus, atau pengaturan papan berubah.
     */
    public function version(Board $board): string
    {
        $posts = Post::withTrashed()->where('board_id', $board->id)
            ->selectRaw("count(*) filter (where deleted_at is null and status = 'published') as visible, max(updated_at) as latest")
            ->first();

        $sections = DB::table('board_sections')->where('board_id', $board->id)
            ->selectRaw('count(*) as c, max(updated_at) as m')->first();

        // Komentar dan reaksi ikut menaikkan updated_at kartu (touch), jadi
        // cukup tercakup oleh max(updated_at) kartu di atas.

        return substr(md5(implode('|', [
            $posts->visible, $posts->latest, $sections->c, $sections->m,
            $board->updated_at?->format('U.u'), $board->scheduleState(),
        ])), 0, 12);
    }

    public function settings(Board $board): array
    {
        return [
            'title' => $board->title,
            'description' => $board->description,
            'icon' => $board->icon,
            'background' => $board->background ?? 'none',
            'format' => $board->format,
            'sort' => $board->sortMode(),
            'visibility' => $board->visibility,
            'class_id' => $board->class_id,
            'theme_id' => $board->theme_id,
            'theme_locked' => $board->theme_locked,
            'visitor_permission' => $board->visitor_permission,
            'require_approval' => $board->requiresApproval(),
            'max_posts' => $board->maxPostsPerStudent(),
            'secret_url' => $board->visibility === 'link' && $board->secret_token ? route('boards.link', $board->secret_token) : null,
            'is_frozen' => $board->is_frozen,
            'allow_anonymous' => (bool) $board->setting('allow_anonymous', false),
            'reaction' => $board->reactionKind(),
            'allow_comments' => $board->allowsComments(),
            'checklist_all' => (bool) $board->setting('checklist_all', false),
            'opens_at' => self::localInput($board->setting('opens_at')),
            'closes_at' => self::localInput($board->setting('closes_at')),
            'rubric' => $board->rubric(),
        ];
    }

    /** ISO UTC -> "YYYY-MM-DDTHH:mm" zona sekolah untuk DateField. */
    private static function localInput(?string $iso): string
    {
        return $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '';
    }

    public function options(): array
    {
        $year = AcademicYear::active();

        return [
            'formats' => Board::FORMATS,
            'sorts' => Board::SORTS,
            'reactions' => \App\Models\Reaction::KINDS,
            'visibilities' => Board::VISIBILITIES,
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get(['id', 'name']) : [],
            'themes' => Theme::orderBy('sort')->get(['id', 'name']),
        ];
    }
}
