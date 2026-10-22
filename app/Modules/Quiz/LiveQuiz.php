<?php

namespace App\Modules\Quiz;

use App\Events\LiveQuizUpdated;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Kuis live (QZ-10..13, AUTH-07).
 *
 * - Status permainan disimpan di kolom `quiz_sessions.live` (ADR-019). Setiap
 *   perpindahan fase dilakukan di dalam transaksi dengan kunci baris, jadi dua
 *   klik "Lanjut" atau host + pemain yang bersamaan tidak saling menimpa.
 * - Pemain = satu percobaan (`quiz_attempts`) per orang; jawaban di `quiz_answers`
 *   sehingga laporan, analisis butir, dan ekspor kuis mandiri ikut terpakai.
 * - Jawaban dikirim lewat HTTP biasa dan dinilai di server. WebSocket (Reverb)
 *   hanya menyiarkan "ada perubahan"; klien lalu mengambil status lewat HTTP.
 *   Kunci jawaban tidak pernah dikirim selama soal masih terbuka (ADR-020).
 * - Tidak ada proses latar: soal yang lewat batas waktu ditutup secara malas
 *   (lazy) saat ada permintaan status/jawaban berikutnya.
 */
class LiveQuiz
{
    /** Jeda membaca soal sebelum pilihan jawaban dibuka. */
    public const READY_SECONDS = 3;

    /** Toleransi jaringan untuk jawaban yang tiba tepat di batas waktu. */
    public const GRACE_MS = 1500;

    public const SECONDS = [10, 20, 30, 45, 60, 90, 120];

    public const TEAM_NAMES = ['Bintang', 'Bulan', 'Petir', 'Hati', 'Daun', 'Ombak'];

    public function __construct(private QuizEngine $engine) {}

    // ------------------------------------------------------------------ buat

    public function create(Quiz $quiz, User $host, array $opts): QuizSession
    {
        $questions = $this->engine->snapshot($quiz);
        $order = array_column($questions, 'id');
        if ($opts['shuffle_questions'] ?? false) {
            shuffle($order);
        }

        $settings = array_merge($quiz->settings(), [
            'live_seconds' => (int) $opts['seconds'],
            'teams' => (int) ($opts['teams'] ?? 0),
            'allow_guests' => (bool) ($opts['allow_guests'] ?? false),
            'leaderboard' => (bool) ($opts['leaderboard'] ?? true),
            'attempts' => 1,
            'shuffle_options' => false, // bentuk A-D harus sama di proyektor dan HP
            'review' => 'after_close',
        ]);

        return QuizSession::create([
            'quiz_id' => $quiz->id,
            'host_id' => $host->id,
            'class_id' => null,
            'mode' => 'live',
            'title' => $quiz->title,
            'settings' => $settings,
            'questions' => $questions,
            'live' => [
                'phase' => 'lobby', 'index' => -1, 'order' => $order,
                'opens_at' => null, 'deadline_at' => null, 'version' => 1, 'kicked' => [],
            ],
        ]);
    }

    // ------------------------------------------------------------- akses

    public function isHost(User $user, QuizSession $s): bool
    {
        return $s->host_id === $user->id || $user->isAdmin() || $s->quiz?->owner_id === $user->id;
    }

    /** Alasan pengguna tidak boleh ikut, atau null bila boleh. */
    public function joinError(User $user, QuizSession $s): ?string
    {
        if (! $s->isLive()) {
            return 'Ini bukan kuis live.';
        }
        if (in_array($user->id, $s->live['kicked'] ?? [], true)) {
            return 'Kamu dikeluarkan dari kuis ini oleh guru.';
        }
        if ($user->isGuest()) {
            return ($s->settings['allow_guests'] ?? false) && ($user->profile['guest_session'] ?? null) === $s->id
                ? null : 'Kuis ini tidak menerima tamu.';
        }
        if (! $user->isStudent()) {
            return 'Hanya siswa yang bisa ikut sebagai pemain.';
        }

        return null;
    }

    public function attemptOf(User $user, QuizSession $s): ?QuizAttempt
    {
        return QuizAttempt::where('session_id', $s->id)->where('user_id', $user->id)->first();
    }

    /** Boleh berlangganan kanal siaran sesi ini. */
    public function canWatch(User $user, QuizSession $s): bool
    {
        return $s->isLive() && ($this->isHost($user, $s) || $this->attemptOf($user, $s) !== null);
    }

    // ------------------------------------------------------------- pemain

    public function join(User $user, QuizSession $s): QuizAttempt
    {
        if ($error = $this->joinError($user, $s)) {
            throw new RuntimeException($error);
        }

        $attempt = DB::transaction(function () use ($user, $s) {
            $s = QuizSession::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->attemptOf($user, $s)) {
                return $existing;
            }
            if ($s->live['phase'] === 'podium') {
                throw new RuntimeException('Kuis sudah selesai.');
            }

            $team = null;
            if ($teams = (int) ($s->settings['teams'] ?? 0)) {
                $sizes = QuizAttempt::where('session_id', $s->id)->whereNotNull('team')->groupBy('team')
                    ->selectRaw('team, count(*) n')->pluck('n', 'team');
                $team = collect(range(1, $teams))->sortBy(fn ($t) => [$sizes[$t] ?? 0, $t])->first();
            }

            return QuizAttempt::create([
                'session_id' => $s->id,
                'user_id' => $user->id,
                'attempt_no' => 1,
                'team' => $team,
                'seed' => random_int(1, 2_000_000_000),
                'question_order' => $s->live['order'],
                'started_at' => now(),
                'max_score' => $s->maxScore(),
            ]);
        });

        if ($attempt->wasRecentlyCreated) {
            $this->broadcast($s, 'players');
        }

        return $attempt;
    }

    /** Simpan jawaban soal yang sedang berjalan. Hasil benar/salah baru terlihat saat pembahasan. */
    public function answer(User $user, QuizSession $s, string $questionId, mixed $response): void
    {
        $attempt = $this->attemptOf($user, $s) ?? throw new RuntimeException('Kamu belum bergabung di kuis ini.');
        $live = $s->live;
        $current = $live['order'][$live['index']] ?? null;

        if ($live['phase'] !== 'question' || $current !== $questionId) {
            throw new RuntimeException('Soal ini sudah ditutup.');
        }

        $now = now();
        $opens = Carbon::parse($live['opens_at']);
        $deadline = Carbon::parse($live['deadline_at']);
        if ($now->lt($opens)) {
            throw new RuntimeException('Tunggu sebentar, pilihan jawaban belum dibuka.');
        }
        if ($now->gt($deadline->copy()->addMilliseconds(self::GRACE_MS))) {
            throw new RuntimeException('Waktu habis.');
        }

        $q = $s->questionMap()[$questionId];
        if ($response === null || $response === '' || $response === []) {
            throw new RuntimeException('Pilih jawaban dulu.');
        }
        $response = $this->engine->cleanResponse($q, $response);
        $result = QuestionData::grade($this->engine->model($q), $response);

        $limitMs = max(1, (int) $opens->diffInMilliseconds($deadline));
        $elapsed = (int) min($limitMs, max(0, $opens->diffInMilliseconds($now)));
        $liveScore = $result['ratio'] > 0
            ? (int) round(1000 * $q['points'] * $result['ratio'] * (1 - 0.5 * $elapsed / $limitMs))
            : 0;

        try {
            QuizAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $questionId,
                'response' => $response,
                'is_correct' => $result['correct'],
                'points' => round($result['ratio'] * $q['points'], 2),
                'live_score' => $liveScore,
                'elapsed_ms' => $elapsed,
                'answered_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Kamu sudah menjawab soal ini.');
        }

        // Semua pemain sudah menjawab: langsung tampilkan pembahasan
        [$answered, $players] = $this->progressCounts($s);
        if ($players > 0 && $answered >= $players) {
            $this->tick($s);
        } else {
            $this->broadcast($s, 'progress', ['answered' => $answered, 'players' => $players]);
        }
    }

    // ---------------------------------------------------------------- host

    public function hostAction(QuizSession $s, string $action, ?string $userId = null): void
    {
        $changed = DB::transaction(function () use ($s, $action, $userId) {
            $s = QuizSession::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $live = $s->live;
            $last = count($live['order']) - 1;

            switch ($action) {
                case 'start':
                    if ($live['phase'] !== 'lobby') {
                        return null;
                    }
                    if (! QuizAttempt::where('session_id', $s->id)->exists()) {
                        throw new RuntimeException('Belum ada pemain yang bergabung.');
                    }
                    $live = $this->openQuestion($s, $live, 0);
                    break;

                case 'reveal': // akhiri soal lebih awal
                    if ($live['phase'] !== 'question') {
                        return null;
                    }
                    $live['phase'] = 'reveal';
                    break;

                case 'next':
                    if ($live['phase'] === 'reveal' && ($s->settings['leaderboard'] ?? true)) {
                        $live['phase'] = 'leaderboard';
                    } elseif (in_array($live['phase'], ['reveal', 'leaderboard'], true)) {
                        if ($live['index'] >= $last) {
                            $this->finishLocked($s, $live);

                            return true;
                        }
                        $live = $this->openQuestion($s, $live, $live['index'] + 1);
                    } else {
                        return null;
                    }
                    break;

                case 'end':
                    if ($live['phase'] === 'podium') {
                        return null;
                    }
                    $this->finishLocked($s, $live);

                    return true;

                case 'kick':
                    $attempt = QuizAttempt::where('session_id', $s->id)->where('user_id', $userId)->first();
                    if (! $attempt) {
                        return null;
                    }
                    $attempt->delete();
                    $live['kicked'] = array_values(array_unique([...($live['kicked'] ?? []), $userId]));
                    break;

                default:
                    throw new RuntimeException('Aksi tidak dikenal.');
            }

            $live['version']++;
            $s->forceFill(['live' => $live])->save();

            return true;
        });

        if ($changed) {
            $this->broadcast($s->fresh(), 'state');
        }
    }

    /**
     * Tutup soal yang lewat batas waktu atau sudah dijawab semua pemain.
     * Aman dipanggil berkali-kali; hanya satu permintaan yang benar-benar mengubah fase.
     */
    public function tick(QuizSession $s): bool
    {
        if (($s->live['phase'] ?? null) !== 'question') {
            return false;
        }

        $changed = DB::transaction(function () use ($s) {
            $s = QuizSession::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $live = $s->live;
            if ($live['phase'] !== 'question') {
                return false;
            }
            $expired = now()->gt(Carbon::parse($live['deadline_at'])->addMilliseconds(self::GRACE_MS));
            [$answered, $players] = $this->progressCounts($s);
            if (! $expired && ! ($players > 0 && $answered >= $players)) {
                return false;
            }
            $live['phase'] = 'reveal';
            $live['version']++;
            $s->forceFill(['live' => $live])->save();

            return true;
        });

        if ($changed) {
            $s->refresh();
            $this->broadcast($s, 'state');
        }

        return $changed;
    }

    private function openQuestion(QuizSession $s, array $live, int $index): array
    {
        $opens = now()->addSeconds(self::READY_SECONDS);
        $live['phase'] = 'question';
        $live['index'] = $index;
        $live['opens_at'] = $opens->toIso8601ZuluString('millisecond');
        $live['deadline_at'] = $opens->copy()->addSeconds((int) ($s->settings['live_seconds'] ?? 20))->toIso8601ZuluString('millisecond');

        return $live;
    }

    /** Akhiri permainan: podium, kumpulkan semua percobaan agar masuk laporan. */
    private function finishLocked(QuizSession $s, array $live): void
    {
        $live['phase'] = 'podium';
        $live['version']++;
        $s->forceFill(['live' => $live, 'closed_at' => now()])->save();

        QuizAttempt::where('session_id', $s->id)->whereNull('submitted_at')->get()
            ->each(fn (QuizAttempt $a) => $a->forceFill([
                'submitted_at' => now(),
                'score' => (float) QuizAnswer::where('attempt_id', $a->id)->sum('points'),
                'max_score' => $s->maxScore(),
            ])->save());
    }

    // --------------------------------------------------------------- status

    /** Status untuk layar host atau pemain. Kunci jawaban hanya ada setelah soal ditutup. */
    public function state(QuizSession $s, User $viewer): array
    {
        $this->tick($s);
        $s->refresh();
        $live = $s->live;
        $host = $this->isHost($viewer, $s);
        $phase = $live['phase'];
        $index = $live['index'];
        $qid = $live['order'][$index] ?? null;
        [$answered, $players] = $this->progressCounts($s);

        $out = [
            'id' => $s->id,
            'title' => $s->title,
            'code' => $s->code,
            'channel' => \App\Events\LiveQuizUpdated::channel($s),
            'phase' => $phase,
            'index' => $index,
            'total' => count($live['order']),
            'version' => $live['version'],
            'server_now' => now()->toIso8601ZuluString('millisecond'),
            'seconds' => (int) ($s->settings['live_seconds'] ?? 20),
            'teams' => $this->teamNames((int) ($s->settings['teams'] ?? 0)),
            'players_count' => $players,
            'answered' => in_array($phase, ['question', 'reveal'], true) ? $answered : 0,
            'opens_at' => $phase === 'question' ? $live['opens_at'] : null,
            'deadline_at' => $phase === 'question' ? $live['deadline_at'] : null,
            'question' => null,
            'reveal' => null,
            'leaderboard' => null,
        ];

        // Bukan host dan bukan pemain (mis. dikeluarkan): hanya status dirinya
        if (! $host && ! $this->attemptOf($viewer, $s)) {
            return $out + ['me' => $this->me($s, $viewer, null, $phase)];
        }

        if ($qid && in_array($phase, ['question', 'reveal'], true)) {
            $out['question'] = $this->publicQuestion($s, $qid, $index);
        }
        if ($qid && $phase === 'reveal') {
            $out['reveal'] = $this->reveal($s, $qid, $host);
        }
        if (in_array($phase, ['leaderboard', 'podium'], true) || ($host && $phase === 'reveal')) {
            $out['leaderboard'] = $this->leaderboard($s, $phase === 'podium' ? 50 : 10);
        }

        if ($host) {
            if ($qid && $phase === 'question') {
                $out['host_counts'] = $this->optionCounts($s, $qid);
            }
            $out['host'] = [
                'join_url' => ($s->settings['allow_guests'] ?? false) ? route('guest.join', ['kode' => $s->code]) : route('boards.join-link', $s->code),
                'allow_guests' => (bool) ($s->settings['allow_guests'] ?? false),
                'leaderboard_on' => (bool) ($s->settings['leaderboard'] ?? true),
                'players' => $this->players($s),
                'report_url' => route('assignments.show', $s->id),
            ];
        } else {
            $out['me'] = $this->me($s, $viewer, $qid, $phase);
        }

        return $out;
    }

    private function publicQuestion(QuizSession $s, string $qid, int $index): array
    {
        $q = $s->questionMap()[$qid];
        $media = $this->engine->mediaCards($s);
        // Kolom kanan "menjodohkan" diacak sama untuk semua pemain (seed dari sesi + nomor soal)
        $seed = crc32($s->id.':'.$index);

        return [
            'id' => $qid,
            'type' => $q['type'],
            'prompt' => $q['prompt'],
            'points' => $q['points'],
            'media' => array_values(array_filter(array_map(fn ($m) => $media[$m] ?? null, $q['media']))),
        ] + QuestionData::forStudent($this->engine->model($q), false, $q['type'] === 'match' ? $seed : null);
    }

    private function reveal(QuizSession $s, string $qid, bool $host): array
    {
        $q = $s->questionMap()[$qid];
        $model = $this->engine->model($q);
        $answers = QuizAnswer::whereIn('attempt_id', QuizAttempt::where('session_id', $s->id)->select('id'))
            ->where('question_id', $qid)->get();

        $dist = [];
        if (in_array($q['type'], ['mc', 'mcm', 'tf'], true)) {
            $key = QuestionData::answerKey($model);
            foreach (QuestionData::forStudent($model)['options'] as $o) {
                $dist[] = [
                    'id' => $o['id'],
                    'text' => $o['text'],
                    'count' => $answers->filter(fn ($a) => in_array($o['id'], (array) $a->response, true))->count(),
                    'correct' => in_array($o['id'], $key, true),
                ];
            }
        } elseif ($q['type'] === 'short') {
            $dist = $answers->groupBy(fn ($a) => QuestionData::normalizeText((string) $a->response))
                ->map(fn ($g, $k) => ['id' => $k, 'text' => (string) $g->first()->response, 'count' => $g->count(), 'correct' => (bool) $g->first()->is_correct])
                ->sortByDesc('count')->take(6)->values()->all();
        } elseif ($q['type'] === 'match') {
            $hits = array_fill_keys(array_column($q['data']['pairs'], 'id'), 0);
            foreach ($answers as $a) {
                foreach (QuestionData::pairResults($q['data'], $a->response) as $pid => $ok) {
                    $hits[$pid] += $ok ? 1 : 0;
                }
            }
            $texts = array_column($q['data']['rights'], 'text', 'id');
            foreach ($q['data']['pairs'] as $pair) {
                $dist[] = ['id' => $pair['id'], 'text' => $pair['left'].' → '.($texts[$pair['right_id']] ?? ''), 'count' => $hits[$pair['id']], 'correct' => true];
            }
        }

        return [
            'key' => QuestionData::answerKey($model),
            'distribution' => $dist,
            'correct_count' => $answers->where('is_correct', true)->count(),
            'answered' => $answers->count(),
            'explanation' => $host ? $q['explanation'] : null,
        ];
    }

    /**
     * Jumlah pemilih tiap pilihan pada soal pilihan ganda/benar-salah, urut sesuai pilihan.
     * Hanya dikirim ke layar host (lewat state), tidak pernah lewat siaran WebSocket,
     * agar siswa tidak ikut-ikutan pilihan terbanyak.
     *
     * @return list<int>|null
     */
    private function optionCounts(QuizSession $s, string $qid): ?array
    {
        $q = $s->questionMap()[$qid];
        if (! in_array($q['type'], ['mc', 'mcm', 'tf'], true)) {
            return null;
        }
        $responses = QuizAnswer::whereIn('attempt_id', QuizAttempt::where('session_id', $s->id)->select('id'))
            ->where('question_id', $qid)->pluck('response');

        return array_map(
            fn ($o) => $responses->filter(fn ($r) => in_array($o['id'], (array) $r, true))->count(),
            QuestionData::forStudent($this->engine->model($q))['options']
        );
    }

    /** @return array{0:int,1:int} [sudah menjawab soal aktif, jumlah pemain] */
    private function progressCounts(QuizSession $s): array
    {
        $players = QuizAttempt::where('session_id', $s->id)->count();
        $qid = $s->live['order'][$s->live['index']] ?? null;
        $answered = $qid
            ? QuizAnswer::whereIn('attempt_id', QuizAttempt::where('session_id', $s->id)->select('id'))->where('question_id', $qid)->count()
            : 0;

        return [$answered, $players];
    }

    /** Skor tiap pemain: jumlah skor live, banyak benar, total waktu (pemecah seri). */
    private function scores(QuizSession $s)
    {
        return DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('quiz_answers as q', 'q.attempt_id', '=', 'a.id')
            ->where('a.session_id', $s->id)
            ->groupBy('a.id', 'a.user_id', 'u.name', 'u.role', 'a.team', 'a.created_at')
            ->selectRaw('a.id as attempt_id, a.user_id, u.name, u.role, a.team, coalesce(sum(q.live_score), 0) as score, coalesce(sum(case when q.is_correct then 1 else 0 end), 0) as correct, coalesce(sum(q.elapsed_ms), 0) as time_ms')
            ->orderByDesc('score')->orderBy('time_ms')->orderBy('a.created_at')
            ->get()
            ->values()
            ->map(fn ($r, $i) => [
                'rank' => $i + 1,
                'user_id' => $r->user_id,
                'name' => $r->name,
                'guest' => $r->role === User::ROLE_GUEST,
                'team' => $r->team ? (int) $r->team : null,
                'score' => (int) $r->score,
                'correct' => (int) $r->correct,
            ]);
    }

    private function leaderboard(QuizSession $s, int $limit): array
    {
        $scores = $this->scores($s);
        $teams = [];
        if ($n = (int) ($s->settings['teams'] ?? 0)) {
            // Skor tim = rata-rata anggota, supaya tim yang lebih kecil tidak dirugikan
            $teams = collect(range(1, $n))->map(function ($t) use ($scores) {
                $m = $scores->where('team', $t);

                return ['team' => $t, 'name' => self::TEAM_NAMES[$t - 1] ?? "Tim $t", 'members' => $m->count(), 'score' => $m->count() ? (int) round($m->avg('score')) : 0];
            })->sortByDesc('score')->values()->all();
        }

        return ['players' => $scores->take($limit)->all(), 'teams' => $teams];
    }

    private function players(QuizSession $s): array
    {
        return $this->scores($s)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    private function me(QuizSession $s, User $viewer, ?string $qid, string $phase): array
    {
        $attempt = $this->attemptOf($viewer, $s);
        $kicked = in_array($viewer->id, $s->live['kicked'] ?? [], true);
        if (! $attempt) {
            return ['joined' => false, 'kicked' => $kicked, 'name' => $viewer->name];
        }

        $row = $this->scores($s)->firstWhere('user_id', $viewer->id);
        $answer = $qid ? QuizAnswer::where('attempt_id', $attempt->id)->where('question_id', $qid)->first() : null;

        return [
            'joined' => true,
            'kicked' => false,
            'name' => $viewer->name,
            'team' => $attempt->team,
            'team_name' => $attempt->team ? (self::TEAM_NAMES[$attempt->team - 1] ?? 'Tim '.$attempt->team) : null,
            'score' => $row['score'] ?? 0,
            'rank' => $row['rank'] ?? null,
            'answered' => (bool) $answer,
            // Benar/salah baru dikirim setelah soal ditutup
            'result' => $answer && in_array($phase, ['reveal', 'leaderboard'], true)
                ? ['correct' => $answer->is_correct, 'live_score' => (int) $answer->live_score, 'points' => $answer->points]
                : null,
            'response' => $answer?->response,
        ];
    }

    private function teamNames(int $n): array
    {
        return $n ? array_slice(self::TEAM_NAMES, 0, $n) : [];
    }

    // ---------------------------------------------------------------- siaran

    /**
     * Siarkan perubahan lewat Reverb. Bila server WebSocket mati, kuis tetap
     * berjalan (klien beralih ke pengecekan berkala); siaran dilewati 30 detik
     * agar setiap jawaban tidak ikut menunggu batas waktu koneksi.
     */
    public function broadcast(QuizSession $s, string $kind, array $extra = []): void
    {
        if (config('broadcasting.default') !== 'reverb' || Cache::get('madingo:realtime-down')) {
            return;
        }

        try {
            broadcast(new LiveQuizUpdated($s, $kind, $extra));
        } catch (Throwable $e) {
            Cache::put('madingo:realtime-down', true, 30);
            report($e);
        }
    }
}
