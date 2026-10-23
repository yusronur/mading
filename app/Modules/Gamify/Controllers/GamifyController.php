<?php

namespace App\Modules\Gamify\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Gamify\Gamify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GamifyController extends Controller
{
    public function __construct(private Gamify $gamify) {}

    /** Halaman Prestasi milik siswa: level, lencana, riwayat XP. */
    public function me(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->gamify->enabled(), 404);

        return Inertia::render('Gamify/Me', [
            'summary' => $this->gamify->mini($user) + ['best_streak' => (int) (\App\Models\UserLevel::where('user_id', $user->id)->value('best_streak') ?? 0)],
            'badges' => $this->gamify->badges($user),
            'history' => $this->gamify->history($user),
            'rules' => collect(config('gamify.reasons'))->map(fn ($label, $k) => ['label' => $label, 'points' => is_array(config("gamify.xp.$k")) ? max(config("gamify.xp.$k")) : config("gamify.xp.$k")])->values(),
            'daily_cap' => config('gamify.daily_cap'),
        ]);
    }

    /** Klien menandai pemberitahuan sudah ditampilkan. */
    public function seen(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['string', 'max:40']])['ids'];
        $this->gamify->dismiss($request->user(), $ids);

        return response()->json(['ok' => true]);
    }

    /** Papan peringkat kelas (GAM-06). Siswa hanya melihat kelasnya sendiri. */
    public function leaderboard(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->gamify->enabled(), 404);
        $year = AcademicYear::active();

        $classes = $user->role === User::ROLE_STUDENT
            ? collect([$user->currentClass()])->filter()
            : ($year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get() : collect());

        $class = $classes->firstWhere('id', $request->query('kelas')) ?? $classes->first();
        $period = $request->query('periode') === 'total' ? 'total' : 'minggu';
        $isStudent = $user->role === User::ROLE_STUDENT;
        $hidden = $class && ! $class->leaderboard && $isStudent;
        $mine = $user->role === User::ROLE_TEACHER && $request->query('sumber') === 'saya';

        return Inertia::render('Gamify/Leaderboard', [
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'leaderboard' => (bool) $c->leaderboard])->values(),
            'class_id' => $class?->id,
            'period' => $period,
            'board' => $class && ! $hidden ? $this->gamify->leaderboard($class, $period, $isStudent ? $user : null, $isStudent ? 10 : 50, $mine ? $user->id : null) : null,
            'hidden' => $hidden,
            'can_toggle' => $user->role === User::ROLE_ADMIN,
            'can_filter' => $user->role === User::ROLE_TEACHER,
            'mine' => $mine,
            'me_id' => $user->id,
        ]);
    }

    public function toggle(Request $request, SchoolClass $class): RedirectResponse
    {
        abort_unless($request->user()->role === User::ROLE_ADMIN, 403);
        $class->update(['leaderboard' => ! $class->leaderboard]);

        return back()->with('success', $class->leaderboard ? 'Papan peringkat kelas ditampilkan ke siswa.' : 'Papan peringkat kelas disembunyikan dari siswa.');
    }
}
