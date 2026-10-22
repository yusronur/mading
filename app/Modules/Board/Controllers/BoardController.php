<?php

namespace App\Modules\Board\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\BoardSection;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\Theme;
use App\Models\User;
use App\Modules\Board\BoardPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function teacherDashboard(Request $request): Response
    {
        $user = $request->user();
        $year = AcademicYear::active();

        $shared = \Illuminate\Support\Facades\DB::table('board_members')
            ->where('user_id', $user->id)->where('role', 'editor')->pluck('board_id');

        $boards = Board::where(fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('id', $shared))
            ->withCount('posts')
            ->with(['schoolClass:id,name', 'owner:id,name'])
            ->latest('updated_at')
            ->get()
            ->map(fn (Board $b) => $this->card($b) + [
                'is_owner' => $b->owner_id === $user->id,
                'archived' => $b->archived_at !== null,
                'folder_id' => $b->folder_id,
                'format' => $b->format,
                'class_id' => $b->class_id,
            ]);

        return Inertia::render('Teacher/Dashboard', [
            'boards' => $boards,
            'folders' => \App\Models\BoardFolder::where('owner_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get(['id', 'name']) : [],
            'themes' => Theme::orderBy('sort')->get(['id', 'name']),
            'visibilities' => Board::VISIBILITIES,
            'formats' => Board::FORMATS,
        ]);
    }

    public function studentDashboard(Request $request): Response
    {
        $user = $request->user();
        $classIds = $user->classes()->pluck('classes.id');
        $class = $user->currentClass();

        $memberOf = \Illuminate\Support\Facades\DB::table('board_members')->where('user_id', $user->id)->pluck('board_id');

        $boards = Board::whereNull('archived_at')
            ->where(function ($q) use ($classIds, $memberOf) {
                $q->where('visibility', 'school')
                    ->orWhere(fn ($w) => $w->where('visibility', 'class')->whereIn('class_id', $classIds))
                    ->orWhereIn('id', $memberOf);
            })
            ->withCount('posts')
            ->with(['schoolClass:id,name', 'owner:id,name'])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Board $b) => $this->card($b));

        return Inertia::render('Student/Dashboard', [
            'boards' => $boards,
            'quizzes' => app(\App\Modules\Quiz\QuizReport::class)->forStudent($user, $classIds->all()),
            'className' => $class?->name,
        ]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        Gate::authorize('create', Board::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::in(array_keys(Board::VISIBILITIES))],
            'class_id' => [
                'nullable', 'required_if:visibility,class', 'uuid',
                Rule::exists('classes', 'id')->where('school_id', $tenant->id()),
            ],
            'theme_id' => ['nullable', 'uuid', Rule::in(Theme::pluck('id')->all())],
            'theme_locked' => ['boolean'],
            'format' => ['nullable', Rule::in(array_keys(Board::FORMATS))],
        ], ['class_id.required_if' => 'Pilih rombel untuk papan satu rombel.'], [
            'title' => 'judul',
            'class_id' => 'rombel',
        ]);

        $format = $data['format'] ?? 'wall';
        unset($data['format']);

        $board = Board::create($data + [
            'secret_token' => $data['visibility'] === 'link' ? \Illuminate\Support\Str::random(32) : null,
            'owner_id' => $request->user()->id,
            'format' => $format,
            'visitor_permission' => 'write',
            'settings' => ['sort' => 'newest'],
            'theme_locked' => (bool) ($data['theme_locked'] ?? false) && ! empty($data['theme_id']),
        ]);

        if ($format === 'shelf') {
            BoardSection::create(['board_id' => $board->id, 'title' => 'Kolom 1', 'position' => 1]);
        }

        return redirect()->route('boards.show', $board)->with('success', 'Papan dibuat. Bagikan kode '.$board->code.' ke siswa.');
    }

    /** Tautan/QR bergabung: /gabung/KODE (SHR-01, SHR-02). */
    public function joinLink(Request $request, string $code): RedirectResponse
    {
        $request->merge(['code' => $code]);

        return $this->join($request);
    }

    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']], [], ['code' => 'kode papan']);
        $board = Board::where('code', strtoupper($data['code']))->first();

        // Papan bertautan rahasia: kode papan juga berfungsi sebagai kunci masuk
        if ($board && $board->visibility === 'link' && ! $board->archived_at) {
            \App\Modules\Board\Controllers\ModerationController::addMember($request->user(), $board);
        }

        // Kode yang sama dipakai untuk kuis (SHR-02)
        if (! $board && ($quiz = \App\Models\QuizSession::where('code', strtoupper($data['code']))->first())) {
            return redirect()->route('take.show', $quiz);
        }

        if (! $board || ! Gate::allows('view', $board)) {
            return redirect()->route('home')->with('error', 'Papan atau kuis dengan kode itu tidak ditemukan, atau bukan untuk kelasmu.');
        }

        return redirect()->route('boards.show', $board);
    }

    public function show(Request $request, Board $board, BoardPresenter $presenter): Response
    {
        Gate::authorize('view', $board);
        $user = $request->user();
        $canManage = Gate::allows('manage', $board);

        return Inertia::render('Boards/Show', [
            'board' => $presenter->board($board),
            'version' => $presenter->version($board),
            'sections' => $presenter->sections($board),
            'posts' => $presenter->posts($board, $user),
            'can' => [
                'post' => Gate::allows('post', $board),
                'manage' => $canManage,
                'interact' => Gate::allows('interact', $board),
                'comment' => Gate::allows('comment', $board),
                'moderate' => Gate::allows('moderate', $board),
                'own' => Gate::allows('own', $board),
            ],
            // Data pengaturan hanya dikirim ke pengelola papan
            'settings' => $canManage ? $presenter->settings($board) : null,
            'options' => $canManage ? $presenter->options() : null,
        ] + $this->activityProps($request, $board));
    }

    /** Layar proyektor (BRD-20): teks besar, aktivitas live memenuhi layar. */
    public function projector(Request $request, Board $board, BoardPresenter $presenter): Response
    {
        Gate::authorize('view', $board);
        $user = $request->user();
        $canManage = Gate::allows('manage', $board);

        return Inertia::render('Boards/Projector', [
            'board' => $presenter->board($board),
            'version' => $presenter->version($board),
            'sections' => $presenter->sections($board),
            'posts' => $presenter->posts($board, $user),
            'can' => ['manage' => $canManage, 'interact' => Gate::allows('interact', $board)],
        ] + $this->activityProps($request, $board));
    }

    private function activityProps(Request $request, Board $board): array
    {
        $payload = app(\App\Modules\Board\Controllers\ActivityController::class)->payload($request, $board);

        return ['activity' => $payload['activity'], 'activity_channel' => $payload['channel']];
    }

    public function update(Request $request, Board $board, TenantContext $tenant): RedirectResponse
    {
        Gate::authorize('manage', $board);

        // Ganti format/urutan cepat dari bilah papan tanpa mengirim seluruh form
        if ($request->boolean('quick')) {
            $data = $request->validate([
                'format' => ['sometimes', Rule::in(array_keys(Board::FORMATS))],
                'sort' => ['sometimes', Rule::in(array_keys(Board::SORTS))],
            ]);
            $this->applyLayout($board, $data);
            $board->save();

            return back();
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:16'],
            'background' => ['nullable', Rule::in(Board::BACKGROUNDS)],
            'format' => ['required', Rule::in(array_keys(Board::FORMATS))],
            'sort' => ['required', Rule::in(array_keys(Board::SORTS))],
            'visibility' => ['required', Rule::in(array_keys(Board::VISIBILITIES))],
            'class_id' => [
                'nullable', 'required_if:visibility,class', 'uuid',
                Rule::exists('classes', 'id')->where('school_id', $tenant->id()),
            ],
            'theme_id' => ['nullable', 'uuid', Rule::in(Theme::pluck('id')->all())],
            'theme_locked' => ['boolean'],
            'visitor_permission' => ['required', Rule::in(['read', 'write', 'moderate'])],
            'require_approval' => ['boolean'],
            'max_posts' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_frozen' => ['boolean'],
            'allow_anonymous' => ['boolean'],
            'reaction' => ['required', Rule::in(array_keys(\App\Models\Reaction::KINDS))],
            'allow_comments' => ['boolean'],
        ], ['class_id.required_if' => 'Pilih rombel untuk papan satu rombel.'], [
            'title' => 'judul',
            'description' => 'petunjuk',
            'class_id' => 'rombel',
        ]);

        $board->fill([
            'title' => strip_tags($data['title']),
            'description' => isset($data['description']) ? strip_tags($data['description']) : null,
            'icon' => isset($data['icon']) ? mb_substr(strip_tags($data['icon']), 0, 4) : null,
            'background' => ($data['background'] ?? 'none') === 'none' ? null : $data['background'],
            'visibility' => $data['visibility'],
            'class_id' => $data['visibility'] === 'class' ? $data['class_id'] : null,
            'theme_id' => $data['theme_id'] ?? null,
            'theme_locked' => (bool) ($data['theme_locked'] ?? false) && ! empty($data['theme_id']),
            'visitor_permission' => $data['visitor_permission'],
            'is_frozen' => (bool) ($data['is_frozen'] ?? false),
        ]);
        $board->settings = array_merge($board->settings ?? [], [
            'allow_anonymous' => (bool) ($data['allow_anonymous'] ?? false),
            'reaction' => $data['reaction'],
            'allow_comments' => (bool) ($data['allow_comments'] ?? false),
            'require_approval' => (bool) ($data['require_approval'] ?? false),
            'max_posts' => (int) ($data['max_posts'] ?? 0),
        ]);
        if ($board->visibility === 'link') {
            $board->ensureSecretToken();
        }
        $this->applyLayout($board, $data);
        $board->save();

        return back()->with('success', 'Pengaturan papan disimpan.');
    }

    /**
     * Format dan urutan bisa diganti kapan saja tanpa kehilangan kartu (BRD-11).
     * Saat pertama kali beralih ke urutan manual, posisi diisi dari urutan
     * yang sedang tampil agar susunan tidak melompat.
     */
    private function applyLayout(Board $board, array $data): void
    {
        if (isset($data['format'])) {
            $board->format = $data['format'];

            if ($data['format'] === 'shelf' && ! $board->sections()->exists()) {
                BoardSection::create(['board_id' => $board->id, 'title' => 'Kolom 1', 'position' => 1]);
                $board->posts()->whereNull('section_id')->update(['section_id' => $board->sections()->value('id')]);
            }
        }

        if (isset($data['sort'])) {
            if ($data['sort'] === 'manual' && $board->sortMode() !== 'manual') {
                $ids = app(BoardPresenter::class)->orderedPosts($board)->pluck('id');
                foreach ($ids as $i => $id) {
                    Post::whereKey($id)->update(['position' => $i + 1]);
                }
            }

            $settings = $board->settings ?? [];
            unset($settings['new_post_position']);
            $board->settings = array_merge($settings, ['sort' => $data['sort']]);
        }
    }

    public function regenerateCode(Board $board): RedirectResponse
    {
        Gate::authorize('own', $board);

        $board->forceFill(['code' => Board::generateCode()])->save();

        return back()->with('success', 'Kode papan diganti menjadi '.$board->code.'. Kode lama tidak berlaku lagi.');
    }

    public function destroy(Board $board): RedirectResponse
    {
        Gate::authorize('own', $board);

        $board->delete();
        \App\Models\AuditLog::record('board.deleted', $board, ['title' => $board->title, 'code' => $board->code]);

        return redirect()->route('home')->with('success', 'Papan dihapus.');
    }

    /**
     * Pemeriksaan ringan untuk pembaruan cepat: hanya mengembalikan penanda
     * versi. Halaman memuat ulang kartu hanya bila penandanya berubah.
     */
    public function sync(Board $board, BoardPresenter $presenter): JsonResponse
    {
        Gate::authorize('view', $board);

        return response()->json(['v' => $presenter->version($board)])->header('Cache-Control', 'no-store');
    }

    private function card(Board $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'icon' => $b->icon,
            'background' => $b->background,
            'code' => $b->code,
            'visibility' => $b->visibility,
            'class_name' => $b->schoolClass?->name,
            'owner' => $b->relationLoaded('owner') ? $b->owner?->name : null,
            'posts_count' => $b->posts_count ?? 0,
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }
}
