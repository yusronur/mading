<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Modules\Quiz\LiveQuiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Kuis live: host (proyektor) dan pemain (HP). Lihat LiveQuiz. */
class LiveController extends Controller
{
    public function __construct(private LiveQuiz $live) {}

    public function store(Request $request, Quiz $quiz): RedirectResponse
    {
        QuizController::authorizeQuiz($request, $quiz);
        $data = $request->validate([
            'seconds' => ['required', 'integer', Rule::in(LiveQuiz::SECONDS)],
            'teams' => ['required', 'integer', 'between:0,6', Rule::notIn([1])],
            'allow_guests' => ['boolean'],
            'leaderboard' => ['boolean'],
            'shuffle_questions' => ['boolean'],
        ], [], ['seconds' => 'waktu per soal', 'teams' => 'jumlah tim']);

        try {
            $session = $this->live->create($quiz, $request->user(), $data);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AuditLog::record('quiz.live_started', null, ['quiz' => $quiz->title, 'code' => $session->code]);

        return redirect()->route('live.host', $session);
    }

    public function host(Request $request, QuizSession $session): Response
    {
        abort_unless($session->isLive() && $this->live->isHost($request->user(), $session), 403);

        return Inertia::render('Live/Host', ['initial' => $this->live->state($session, $request->user())]);
    }

    public function play(Request $request, QuizSession $session): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $session->isLive()) {
            return redirect()->route('take.show', $session);
        }
        if ($this->live->isHost($user, $session)) {
            return redirect()->route('live.host', $session);
        }

        try {
            $this->live->join($user, $session);
        } catch (RuntimeException $e) {
            // Pemain lama tetap bisa melihat hasil akhir walau kuis sudah selesai
            if (! $this->live->attemptOf($user, $session)) {
                return redirect()->route('home')->with('error', $e->getMessage());
            }
        }

        return Inertia::render('Live/Play', ['initial' => $this->live->state($session, $user)]);
    }

    public function state(Request $request, QuizSession $session): JsonResponse
    {
        $user = $request->user();
        abort_unless($session->isLive() && ($this->live->isHost($user, $session) || $this->live->attemptOf($user, $session) || in_array($user->id, $session->live['kicked'] ?? [], true)), 403);

        return response()->json($this->live->state($session, $user))->header('Cache-Control', 'no-store');
    }

    public function answer(Request $request, QuizSession $session): JsonResponse
    {
        $data = $request->validate(['question_id' => ['required', 'uuid'], 'response' => ['nullable']]);
        abort_unless($session->isLive(), 404);

        try {
            $this->live->answer($request->user(), $session, $data['question_id'], $data['response'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function action(Request $request, QuizSession $session): JsonResponse
    {
        abort_unless($session->isLive() && $this->live->isHost($request->user(), $session), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'reveal', 'next', 'end', 'kick'])],
            'user_id' => ['required_if:action,kick', 'nullable', 'uuid'],
        ]);

        try {
            $this->live->hostAction($session, $data['action'], $data['user_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->live->state($session->fresh(), $request->user()));
    }
}
