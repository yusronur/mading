<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Modules\Quiz\QuizEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Siswa mengerjakan kuis mandiri (QZ-09, QZ-18). */
class TakeController extends Controller
{
    public function __construct(private QuizEngine $engine) {}

    /** Halaman pembuka: info kuis, mulai/lanjut, hasil sebelumnya. */
    public function show(Request $request, QuizSession $session): Response|RedirectResponse
    {
        $user = $request->user();

        if ($session->isLive()) {
            return redirect()->route('live.play', $session);
        }
        if (! $user->isStudent() && ! $user->isGuest()) {
            return AssignmentController::authorizeAndRedirect($request, $session);
        }
        if (! $this->engine->canTake($user, $session)) {
            return redirect()->route('home')->with('error', 'Kuis ini bukan untuk rombelmu.');
        }

        $attempts = $this->engine->attemptsOf($user, $session);
        $showScore = (bool) ($session->settings['show_score'] ?? true);

        return Inertia::render('Quiz/Start', [
            'isGuest' => $user->isGuest(),
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'description' => $session->quiz?->description,
                'teacher' => $session->host?->name,
                'questions' => count($session->questions),
                'max_score' => $session->maxScore(),
                'duration' => $session->settings['duration_minutes'] ?? null,
                'attempts' => (int) ($session->settings['attempts'] ?? 1),
                'opens_at' => $session->opens_at?->toIso8601String(),
                'closes_at' => $session->closes_at?->toIso8601String(),
                'status' => $session->status(),
            ],
            'attempts' => $attempts->map(fn (QuizAttempt $a) => [
                'id' => $a->id,
                'no' => $a->attempt_no,
                'submitted' => (bool) $a->submitted_at,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'grade' => $showScore && $a->submitted_at ? $a->grade() : null,
                'can_review' => $this->engine->canReview($a, $session),
            ]),
            'attemptsLeft' => $this->engine->attemptsLeft($session, $attempts),
            'inProgress' => $attempts->first(fn ($a) => ! $a->submitted_at)?->id,
        ]);
    }

    public function start(Request $request, QuizSession $session): RedirectResponse
    {
        try {
            $attempt = $this->engine->start($request->user(), $session);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('take.attempt', $attempt);
    }

    public function attempt(Request $request, QuizAttempt $attempt): Response|RedirectResponse
    {
        $this->authorizeAttempt($request, $attempt);
        $session = $attempt->session;
        $this->engine->finalizeIfExpired($attempt, $session);

        if ($attempt->submitted_at) {
            return redirect()->route('take.result', $attempt);
        }

        return Inertia::render('Quiz/Take', [
            'session' => ['id' => $session->id, 'title' => $session->title],
            'attempt' => [
                'id' => $attempt->id,
                'deadline_at' => $attempt->deadline_at?->toIso8601String(),
                'server_now' => now()->toIso8601String(),
            ],
            'questions' => $this->engine->studentQuestions($attempt, $session),
        ]);
    }

    /** Simpan satu jawaban (otomatis). Tidak pernah mengembalikan benar/salah. */
    public function answer(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($request, $attempt);
        $data = $request->validate(['question_id' => ['required', 'uuid'], 'response' => ['nullable']]);

        try {
            $this->engine->answer($attempt, $attempt->session, $data['question_id'], $data['response'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'submitted' => (bool) $attempt->fresh()->submitted_at], 422);
        }

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    /** Catat siswa meninggalkan halaman kuis (untuk laporan guru). */
    public function focus(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($request, $attempt);
        if (! $attempt->submitted_at) {
            $attempt->increment('focus_lost');
        }

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $this->authorizeAttempt($request, $attempt);
        $this->engine->submit($attempt, $attempt->session);

        return redirect()->route('take.result', $attempt)->with('success', 'Jawaban dikumpulkan. Kerja bagus! 🎉');
    }

    public function result(Request $request, QuizAttempt $attempt): Response|RedirectResponse
    {
        $this->authorizeAttempt($request, $attempt);
        $session = $attempt->session;
        $this->engine->finalizeIfExpired($attempt, $session);

        if (! $attempt->submitted_at) {
            return redirect()->route('take.attempt', $attempt);
        }

        $canReview = $this->engine->canReview($attempt, $session);
        $showScore = (bool) ($session->settings['show_score'] ?? true);

        return Inertia::render('Quiz/Result', [
            'session' => ['id' => $session->id, 'title' => $session->title, 'review' => $session->settings['review'] ?? 'after_submit', 'kkm' => $session->settings['kkm'] ?? 75],
            'attempt' => [
                'id' => $attempt->id,
                'attempt_no' => $attempt->attempt_no,
                'score' => $showScore ? $attempt->score : null,
                'max_score' => $attempt->max_score,
                'grade' => $showScore ? $attempt->grade() : null,
                'submitted_at' => $attempt->submitted_at->toIso8601String(),
                'answered' => $attempt->answers()->count(),
                'total' => count($attempt->question_order),
            ],
            'review' => $canReview ? $this->engine->review($attempt, $session) : [],
            'canReview' => $canReview,
            'teacherView' => false,
            'isGuest' => $request->user()->isGuest(),
        ]);
    }

    private function authorizeAttempt(Request $request, QuizAttempt $attempt): void
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
    }
}
