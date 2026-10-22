<?php

namespace App\Modules\Quiz;

use App\Models\Attachment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inti kuis mandiri (QZ-08, QZ-09, QZ-18).
 *
 * - Soal disalin (snapshot) saat kuis ditugaskan; mengubah bank soal tidak
 *   mengubah penilaian tugas yang sudah berjalan.
 * - Jawaban dinilai di server saat disimpan; kunci tidak pernah dikirim
 *   ke peramban sebelum pembahasan dibuka.
 * - Batas waktu dihitung server: min(mulai + durasi, tenggat).
 */
class QuizEngine
{
    /** Toleransi jaringan untuk jawaban yang tiba tepat saat waktu habis. */
    public const GRACE_SECONDS = 5;

    public function snapshot(Quiz $quiz): array
    {
        $questions = $quiz->questions()->with('attachments')->get();
        if ($questions->isEmpty()) {
            throw new RuntimeException('Kuis belum berisi soal.');
        }

        return $questions->map(fn (Question $q) => [
            'id' => $q->id,
            'type' => $q->type,
            'prompt' => $q->prompt,
            'explanation' => $q->explanation,
            'data' => $q->data,
            'points' => (int) ($q->pivot->points ?? $q->points),
            'difficulty' => $q->difficulty,
            'tags' => $q->tags ?? [],
            'media' => $q->attachments->pluck('id')->all(),
        ])->values()->all();
    }

    public function canTake(User $user, QuizSession $session): bool
    {
        // Tamu: hanya untuk satu kuis publik yang mengizinkan tamu, yang dipakai saat ia masuk
        if ($user->isGuest()) {
            return ! $session->class_id
                && ($session->settings['allow_guests'] ?? false)
                && ($user->profile['guest_session'] ?? null) === $session->id;
        }
        if (! $user->isStudent()) {
            return false;
        }
        // Tugas publik (tanpa rombel): siapa pun di sekolah yang punya kodenya
        if (! $session->class_id) {
            return true;
        }

        return DB::table('class_members')->where('class_id', $session->class_id)->where('user_id', $user->id)->exists();
    }

    /** @return Collection<int, QuizAttempt> */
    public function attemptsOf(User $user, QuizSession $session): Collection
    {
        return QuizAttempt::where('session_id', $session->id)->where('user_id', $user->id)->orderBy('attempt_no')->get()
            ->each(fn (QuizAttempt $a) => $this->finalizeIfExpired($a, $session));
    }

    public function attemptsLeft(QuizSession $session, Collection $attempts): ?int
    {
        $max = (int) ($session->settings['attempts'] ?? 1);

        return $max === 0 ? null : max(0, $max - $attempts->count());
    }

    /** Mulai atau lanjutkan percobaan. */
    public function start(User $user, QuizSession $session): QuizAttempt
    {
        if (! $this->canTake($user, $session)) {
            throw new RuntimeException('Kuis ini bukan untuk rombelmu.');
        }

        return DB::transaction(function () use ($user, $session) {
            // Kunci baris agar dua klik "Mulai" tidak membuat dua percobaan
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $attempts = $this->attemptsOf($user, $session);

            if ($open = $attempts->first(fn (QuizAttempt $a) => ! $a->submitted_at)) {
                return $open;
            }
            if (! $session->isOpen()) {
                throw new RuntimeException($session->isClosed() ? 'Kuis sudah ditutup.' : 'Kuis belum dibuka.');
            }
            if ($this->attemptsLeft($session, $attempts) === 0) {
                throw new RuntimeException('Kesempatan mengerjakan sudah habis.');
            }

            $settings = $session->settings;
            $seed = random_int(1, 2_000_000_000);
            $order = array_column($session->questions, 'id');
            if ($settings['shuffle_questions'] ?? false) {
                $order = $this->shuffle($order, $seed);
            }

            $now = now();
            $deadline = ! empty($settings['duration_minutes']) ? $now->copy()->addMinutes((int) $settings['duration_minutes']) : null;
            if ($session->closes_at && (! $deadline || $session->closes_at->lt($deadline))) {
                $deadline = $session->closes_at->copy();
            }

            return QuizAttempt::create([
                'session_id' => $session->id,
                'user_id' => $user->id,
                'attempt_no' => $attempts->count() + 1,
                'seed' => $seed,
                'question_order' => $order,
                'started_at' => $now,
                'deadline_at' => $deadline,
                'max_score' => $session->maxScore(),
            ]);
        });
    }

    /** Simpan satu jawaban (otomatis tiap soal). null/'' = kosongkan. */
    public function answer(QuizAttempt $attempt, QuizSession $session, string $questionId, mixed $response): void
    {
        if ($attempt->submitted_at) {
            throw new RuntimeException('Jawaban sudah dikumpulkan.');
        }
        if ($attempt->deadline_at && $attempt->deadline_at->copy()->addSeconds(self::GRACE_SECONDS)->lte(now())) {
            $this->submit($attempt, $session);
            throw new RuntimeException('Waktu habis. Jawabanmu sudah dikumpulkan otomatis.');
        }

        $q = $session->questionMap()[$questionId] ?? throw new RuntimeException('Soal tidak ditemukan.');

        if ($response === null || $response === '' || $response === []) {
            QuizAnswer::where('attempt_id', $attempt->id)->where('question_id', $questionId)->delete();

            return;
        }

        $response = $this->cleanResponse($q, $response);
        $result = QuestionData::grade($this->model($q), $response);

        QuizAnswer::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $questionId],
            ['response' => $response, 'is_correct' => $result['correct'], 'points' => round($result['ratio'] * $q['points'], 2), 'answered_at' => now()],
        );
    }

    public function submit(QuizAttempt $attempt, QuizSession $session): QuizAttempt
    {
        if ($attempt->submitted_at) {
            return $attempt;
        }

        $at = now();
        if ($attempt->deadline_at && $attempt->deadline_at->lt($at)) {
            $at = $attempt->deadline_at->copy();
        }

        $attempt->forceFill([
            'submitted_at' => $at,
            'score' => (float) QuizAnswer::where('attempt_id', $attempt->id)->sum('points'),
            'max_score' => $session->maxScore(),
        ])->save();

        return $attempt;
    }

    public function finalizeIfExpired(QuizAttempt $attempt, QuizSession $session): void
    {
        $sessionEnded = $session->closed_at && $session->closed_at->lte(now());
        if (! $attempt->submitted_at && ($attempt->isExpired() || $sessionEnded)) {
            $this->submit($attempt, $session);
        }
    }

    public function canReview(QuizAttempt $attempt, QuizSession $session): bool
    {
        if (! $attempt->submitted_at) {
            return false;
        }

        return match ($session->settings['review'] ?? 'after_submit') {
            'after_submit' => true,
            'after_close' => $session->isClosed(),
            default => false,
        };
    }

    /** Soal untuk dikerjakan siswa, tanpa kunci. */
    public function studentQuestions(QuizAttempt $attempt, QuizSession $session): array
    {
        $map = $session->questionMap();
        $answers = QuizAnswer::where('attempt_id', $attempt->id)->pluck('response', 'question_id');
        $media = $this->mediaCards($session);
        $shuffle = (bool) ($session->settings['shuffle_options'] ?? false);

        return collect($attempt->question_order)->filter(fn ($id) => isset($map[$id]))->values()->map(function ($id, $i) use ($map, $answers, $media, $shuffle, $attempt) {
            $q = $map[$id];

            return [
                'id' => $id,
                'type' => $q['type'],
                'prompt' => $q['prompt'],
                'points' => $q['points'],
                'media' => array_values(array_filter(array_map(fn ($m) => $media[$m] ?? null, $q['media']))),
                'response' => $answers[$id] ?? null,
            ] + QuestionData::forStudent($this->model($q), $shuffle, $attempt->seed + $i);
        })->all();
    }

    /** Pembahasan: jawaban siswa, kunci, dan penjelasan. */
    public function review(QuizAttempt $attempt, QuizSession $session): array
    {
        $map = $session->questionMap();
        $answers = QuizAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');
        $media = $this->mediaCards($session);

        return collect($attempt->question_order)->filter(fn ($id) => isset($map[$id]))->values()->map(function ($id) use ($map, $answers, $media) {
            $q = $map[$id];
            $model = $this->model($q);
            $a = $answers[$id] ?? null;

            return [
                'id' => $id,
                'type' => $q['type'],
                'prompt' => $q['prompt'],
                'explanation' => $q['explanation'],
                'points' => $q['points'],
                'earned' => $a?->points ?? 0,
                'correct' => (bool) $a?->is_correct,
                'response' => $a?->response,
                'key' => QuestionData::answerKey($model),
                'media' => array_values(array_filter(array_map(fn ($m) => $media[$m] ?? null, $q['media']))),
            ] + QuestionData::forStudent($model);
        })->all();
    }

    /** Percobaan yang dihitung untuk nilai (terbaik atau terakhir). */
    public function countedAttempt(Collection $attempts, QuizSession $session): ?QuizAttempt
    {
        $done = $attempts->filter(fn (QuizAttempt $a) => $a->submitted_at);
        if ($done->isEmpty()) {
            return null;
        }

        return ($session->settings['scoring'] ?? 'best') === 'last'
            ? $done->sortBy('attempt_no')->last()
            : $done->sortByDesc('score')->first();
    }

    public function model(array $q): Question
    {
        return new Question(['type' => $q['type'], 'data' => $q['data']]);
    }

    public function mediaCards(QuizSession $session): array
    {
        $ids = collect($session->questions)->pluck('media')->flatten()->filter()->all();

        return $ids ? Attachment::whereIn('id', $ids)->get()->mapWithKeys(fn (Attachment $a) => [$a->id => $a->toCard()])->all() : [];
    }

    public function cleanResponse(array $q, mixed $r): mixed
    {
        $ids = array_column($q['data']['options'] ?? [], 'id');
        $bad = fn () => throw new RuntimeException('Jawaban tidak valid.');

        return match ($q['type']) {
            'mc' => is_string($r) && in_array($r, $ids, true) ? $r : $bad(),
            'mcm' => is_array($r) && ! array_diff($r, $ids) ? array_values(array_unique(array_map('strval', $r))) : $bad(),
            'tf' => in_array($r, ['true', 'false', true, false], true) ? ($r === true || $r === 'true' ? 'true' : 'false') : $bad(),
            'short' => is_string($r) ? mb_substr(trim($r), 0, 200) : $bad(),
            'match' => $this->cleanMatch($q['data'], $r) ?? $bad(),
            default => $bad(),
        };
    }

    /** {id_kiri: id_kanan}; id harus dikenal dan satu kartu kanan hanya dipakai sekali. */
    private function cleanMatch(array $data, mixed $r): ?array
    {
        if (! is_array($r)) {
            return null;
        }
        $lefts = array_column($data['pairs'], 'id');
        $rights = array_column($data['rights'], 'id');
        $out = [];
        foreach ($r as $left => $right) {
            if (! in_array((string) $left, $lefts, true) || ! is_string($right) || ! in_array($right, $rights, true)) {
                return null;
            }
            $out[(string) $left] = $right;
        }

        return count($out) === count(array_unique($out)) ? $out : null;
    }

    private function shuffle(array $items, int $seed): array
    {
        mt_srand($seed);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        mt_srand();

        return $items;
    }
}
