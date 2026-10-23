<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Post;
use App\Models\PostGrade;
use App\Models\User;
use App\Models\UserPreference;
use App\Modules\Notify\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Penilaian kartu dengan rubrik dan komentar cepat guru (Tahap 18). */
class GradeController extends Controller
{
    public const DEFAULT_QUICK = [
        'Bagus sekali! 👍', 'Kerja yang rapi dan lengkap.', 'Idenya menarik, coba tambahkan contoh.',
        'Perhatikan ejaan dan tanda baca.', 'Tambahkan sumber atau referensi.', 'Sudah baik, lanjutkan! 💪',
    ];

    public function grade(Request $request, Board $board, Post $post, Notify $notify): JsonResponse
    {
        abort_unless($post->board_id === $board->id, 404);
        Gate::authorize('manage', $board);
        $rubric = $board->rubric();
        abort_if(! $rubric, 422, 'Papan ini belum punya rubrik. Atur di Pengaturan papan.');

        $author = User::find($post->author_id);
        abort_unless($author && $author->role === User::ROLE_STUDENT, 422, 'Hanya kartu siswa yang dinilai.');

        $data = $request->validate([
            'scores' => ['required', 'array', 'size:'.count($rubric)],
            'scores.*' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ], ['scores.size' => 'Isi skor untuk setiap kriteria.'], ['scores' => 'skor', 'feedback' => 'umpan balik']);

        $scores = [];
        foreach ($rubric as $i => $c) {
            $v = $data['scores'][$i] ?? null;
            if ($v === null || $v === '') {
                throw ValidationException::withMessages(["scores.$i" => "Skor \"{$c['name']}\" belum diisi."]);
            }
            if ((float) $v > $c['max']) {
                throw ValidationException::withMessages(["scores.$i" => "Skor \"{$c['name']}\" maksimal {$c['max']}."]);
            }
            $scores[] = round((float) $v, 1);
        }
        $total = array_sum($scores);
        $max = array_sum(array_column($rubric, 'max'));

        $grade = PostGrade::updateOrCreate(['post_id' => $post->id], [
            'board_id' => $board->id, 'student_id' => $author->id, 'grader_id' => $request->user()->id,
            'scores' => $scores, 'total' => $total, 'max' => $max, 'grade' => (int) round($total / $max * 100),
            'feedback' => ($f = trim(strip_tags((string) ($data['feedback'] ?? '')))) !== '' ? $f : null,
        ]);
        $post->touch(); // ikut tersinkron ke layar siswa

        if ($grade->wasRecentlyCreated) {
            $notify->send($author, 'grade_ready', 'Kartumu sudah dinilai: '.$grade->grade,
                $board->title, route('boards.show', $board->id, false).'?kartu='.$post->id, 'grade:'.$post->id);
        }

        return response()->json(['grade' => $grade->load('grader:id,name')->toCard()]);
    }

    public function ungrade(Board $board, Post $post): JsonResponse
    {
        abort_unless($post->board_id === $board->id, 404);
        Gate::authorize('manage', $board);
        PostGrade::where('post_id', $post->id)->delete();
        $post->touch();

        return response()->json(['grade' => null]);
    }

    /** Daftar komentar cepat milik guru. */
    public static function quickFor(User $user): array
    {
        return UserPreference::where('user_id', $user->id)->value('quick_comments') ?? self::DEFAULT_QUICK;
    }

    public function saveQuick(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ROLE_TEACHER, User::ROLE_ADMIN), 403);
        $items = $request->validate(['items' => ['present', 'array', 'max:20'], 'items.*' => ['nullable', 'string', 'max:160']], [], ['items.*' => 'komentar'])['items'];
        $items = array_values(array_unique(array_filter(array_map(fn ($t) => trim(strip_tags((string) $t)), $items))));
        UserPreference::updateOrCreate(['user_id' => $request->user()->id], ['quick_comments' => $items]);

        return response()->json(['items' => $items]);
    }
}
