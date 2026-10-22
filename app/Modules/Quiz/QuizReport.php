<?php

namespace App\Modules\Quiz;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Laporan kuis: nilai per siswa, analisis butir, remedial (QZ-14). */
class QuizReport
{
    public function __construct(private QuizEngine $engine) {}

    public function build(QuizSession $session): array
    {
        [$kkm, $rows] = $this->rows($session);
        $countedIds = $rows->pluck('attempt_id')->filter()->all();

        return [
            'kkm' => $kkm,
            'rows' => $rows->all(),
            'summary' => $this->summary($rows),
            'items' => $this->items($session, $countedIds),
            'remedial' => $rows->filter(fn ($r) => ! $r['passed'])->values()->all(),
        ];
    }

    /**
     * Pantauan langsung (QZ-14): hanya baris siswa + ringkasan, tanpa analisis
     * butir, supaya ringan dipanggil tiap beberapa detik selama kuis berjalan.
     */
    public function progress(QuizSession $session): array
    {
        [$kkm, $rows] = $this->rows($session);

        return [
            'status' => $session->status(),
            'kkm' => $kkm,
            'rows' => $rows->all(),
            'summary' => $this->summary($rows),
            'remedial_count' => $rows->filter(fn ($r) => ! $r['passed'])->count(),
            'server_now' => now()->toIso8601String(),
        ];
    }

    /** @return array{0: int, 1: \Illuminate\Support\Collection} */
    private function rows(QuizSession $session): array
    {
        $kkm = (int) ($session->settings['kkm'] ?? 75);
        $attempts = QuizAttempt::where('session_id', $session->id)->get()
            ->each(fn (QuizAttempt $a) => $this->engine->finalizeIfExpired($a, $session))
            ->groupBy('user_id');

        // Tugas publik: peserta = siapa saja yang sudah memulai
        $memberIds = $session->class_id
            ? DB::table('class_members')->where('class_id', $session->class_id)->pluck('user_id')
            : $attempts->keys();
        $students = User::whereIn('id', $memberIds)
            ->whereIn('role', [User::ROLE_STUDENT, User::ROLE_GUEST])->orderBy('name')->get(['id', 'name', 'username', 'status', 'role']);

        // Kemajuan percobaan yang sedang berjalan: jumlah soal terjawab dan skor sementara
        $activeIds = $attempts->flatten(1)->filter(fn ($a) => ! $a->submitted_at)->pluck('id')->all();
        $live = $activeIds
            ? QuizAnswer::whereIn('attempt_id', $activeIds)->groupBy('attempt_id')
                ->selectRaw('attempt_id, count(*) as n, coalesce(sum(points), 0) as pts, max(answered_at) as last')->get()->keyBy('attempt_id')
            : collect();
        $total = count($session->questions);
        $max = $session->maxScore();

        $rows = $students->map(function (User $u) use ($attempts, $session, $kkm, $live, $total, $max) {
            $mine = $attempts[$u->id] ?? collect();
            $counted = $this->engine->countedAttempt($mine, $session);
            $active = $mine->first(fn ($a) => ! $a->submitted_at);
            $grade = $counted?->grade();
            $l = $active ? $live[$active->id] ?? null : null;
            $scoreNow = $l ? round((float) $l->pts, 2) : 0.0;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->isGuest() ? null : $u->username,
                'guest' => $u->isGuest(),
                'status' => $active ? 'working' : ($counted ? 'done' : 'none'),
                'attempts' => $mine->count(),
                'attempt_id' => $counted?->id,
                'score' => $counted?->score,
                'max_score' => $counted?->max_score,
                'grade' => $grade,
                'passed' => $grade !== null && $grade >= $kkm,
                'duration_seconds' => $counted ? (int) $counted->started_at->diffInSeconds($counted->submitted_at) : null,
                'submitted_at' => $counted?->submitted_at?->toIso8601String(),
                'focus_lost' => (int) $mine->sum('focus_lost'),
                // Langsung: hanya terisi bila siswa sedang mengerjakan
                'answered' => $active ? (int) ($l?->n ?? 0) : null,
                'total' => $total,
                'score_now' => $active ? $scoreNow : null,
                'grade_now' => $active && $max > 0 ? (int) round($scoreNow / $max * 100) : null,
                'last_active' => $active ? ($l?->last ? \Illuminate\Support\Carbon::parse($l->last)->toIso8601String() : $active->started_at?->toIso8601String()) : null,
                'deadline_at' => $active?->deadline_at?->toIso8601String(),
            ];
        })->values();

        return [$kkm, $rows];
    }

    private function summary($rows): array
    {
        $grades = $rows->pluck('grade')->filter(fn ($g) => $g !== null);

        return [
            'students' => $rows->count(),
            'done' => $grades->count(),
            'working' => $rows->where('status', 'working')->count(),
            'average' => $grades->count() ? round($grades->avg(), 1) : null,
            'highest' => $grades->max(),
            'lowest' => $grades->min(),
            'passed' => $rows->where('passed', true)->count(),
            'answers_in' => (int) $rows->sum(fn ($r) => $r['answered'] ?? 0),
        ];
    }

    /** Analisis butir: tingkat kesukaran (p) dan sebaran jawaban. */
    private function items(QuizSession $session, array $attemptIds): array
    {
        $answers = $attemptIds ? QuizAnswer::whereIn('attempt_id', $attemptIds)->get()->groupBy('question_id') : collect();
        $n = count($attemptIds);

        return collect($session->questions)->values()->map(function ($q, $i) use ($answers, $n) {
            $mine = $answers[$q['id']] ?? collect();
            $correct = $mine->where('is_correct', true)->count();
            $p = $n ? round($correct / $n, 2) : null;

            $dist = [];
            if (in_array($q['type'], ['mc', 'mcm'], true)) {
                foreach ($q['data']['options'] as $o) {
                    $dist[] = ['label' => strtoupper($o['id']), 'text' => $o['text'],
                        'count' => $mine->filter(fn ($a) => in_array($o['id'], (array) $a->response, true))->count(),
                        'correct' => in_array($o['id'], $q['type'] === 'mc' ? [$q['data']['answer']] : $q['data']['answers'], true)];
                }
            } elseif ($q['type'] === 'tf') {
                foreach (['true' => 'Benar', 'false' => 'Salah'] as $k => $label) {
                    $dist[] = ['label' => $label, 'text' => '', 'count' => $mine->filter(fn ($a) => $a->response === $k)->count(),
                        'correct' => ($q['data']['answer'] ? 'true' : 'false') === $k];
                }
            } elseif ($q['type'] === 'match') {
                // Berapa siswa yang menjodohkan tiap pasangan dengan benar
                $hits = array_fill_keys(array_column($q['data']['pairs'], 'id'), 0);
                foreach ($mine as $a) {
                    foreach (QuestionData::pairResults($q['data'], $a->response) as $pid => $ok) {
                        $hits[$pid] += $ok ? 1 : 0;
                    }
                }
                $texts = array_column($q['data']['rights'], 'text', 'id');
                foreach ($q['data']['pairs'] as $k => $pair) {
                    $dist[] = ['label' => ($k + 1).'. '.$pair['left'], 'text' => '→ '.($texts[$pair['right_id']] ?? ''), 'count' => $hits[$pair['id']], 'correct' => true];
                }
            } else {
                // Jawaban salah terbanyak untuk isian singkat
                $dist = $mine->where('is_correct', false)->groupBy(fn ($a) => QuestionData::normalizeText((string) $a->response))
                    ->map(fn ($g, $k) => ['label' => $k, 'text' => '', 'count' => $g->count(), 'correct' => false])
                    ->sortByDesc('count')->take(5)->values()->all();
            }

            return [
                'no' => $i + 1,
                'id' => $q['id'],
                'type' => $q['type'],
                'prompt' => $q['prompt'],
                'points' => $q['points'],
                'answered' => $mine->count(),
                'correct' => $correct,
                'p' => $p,
                'level' => $p === null ? null : ($p >= 0.7 ? 'Mudah' : ($p >= 0.3 ? 'Sedang' : 'Sukar')),
                'distribution' => $dist,
                'key' => QuestionData::answerKey($this->engine->model($q)),
            ];
        })->all();
    }

    /** Daftar kuis untuk beranda siswa. */
    public function forStudent(User $user, array $classIds): array
    {
        // Tugas rombel + tugas publik yang pernah dimulai siswa ini
        $joined = QuizAttempt::where('user_id', $user->id)->pluck('session_id');
        if (! $classIds && $joined->isEmpty()) {
            return [];
        }

        $sessions = QuizSession::where(fn ($q) => $q->whereIn('class_id', $classIds)->orWhereIn('id', $joined))
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()->subDays(14)))
            ->with('host:id,name')
            ->orderByRaw('closes_at is null, closes_at asc')
            ->limit(30)->get();

        // Kuis live yang ditinggalkan guru ditutup dulu agar tidak tampil sebagai "lanjutkan mengerjakan"
        $live = app(LiveQuiz::class);
        $sessions->filter->isLive()->each(fn (QuizSession $s) => $live->finishIfStale($s));
        $attempts = QuizAttempt::whereIn('session_id', $sessions->pluck('id'))->where('user_id', $user->id)->get()->groupBy('session_id');

        return $sessions->map(function (QuizSession $s) use ($attempts) {
            $mine = ($attempts[$s->id] ?? collect())->each(fn ($a) => $this->engine->finalizeIfExpired($a, $s));
            $counted = $this->engine->countedAttempt($mine, $s);

            return [
                'id' => $s->id,
                'title' => $s->title,
                'teacher' => $s->host?->name,
                'questions' => count($s->questions),
                'duration' => $s->settings['duration_minutes'] ?? null,
                'closes_at' => $s->closes_at?->toIso8601String(),
                'status' => $s->status(),
                'mine' => $counted ? 'done' : ($mine->first(fn ($a) => ! $a->submitted_at) ? 'working' : 'none'),
                'grade' => $counted && ($s->settings['show_score'] ?? true) ? $counted->grade() : null,
            ];
        })->sortBy(fn ($q) => [$q['status'] === 'open' && $q['mine'] !== 'done' ? 0 : 1])->values()->all();
    }
}
